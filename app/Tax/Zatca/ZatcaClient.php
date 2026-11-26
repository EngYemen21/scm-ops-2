<?php

namespace App\Tax\Zatca;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The authority's e-invoicing services (the "Fatoora" gateway), in their three environments:
 *
 *   {gateway}/developer-portal   a sandbox that needs no registration
 *   {gateway}/simulation         the simulation of the real portal (its own one-time passwords and certificates)
 *   {gateway}/core               production
 *
 *   POST  compliance                   a compliance certificate for a certificate request   (header OTP)
 *   POST  compliance/invoices          one sample document checked with the compliance certificate
 *   POST  production/csids             the production certificate, for a compliance request id
 *   PATCH production/csids             a renewed production certificate                    (header OTP)
 *   POST  invoices/reporting/single    report a simplified document                        (Clearance-Status: 0)
 *   POST  invoices/clearance/single    clear a standard document                           (Clearance-Status: 1)
 *
 * Every call carries `Accept-Version: V2`; the calls after the first authenticate with HTTP Basic, the certificate
 * token being the user and its secret the password (Security Features Implementation Standards v1.2 §5).
 * 200 = accepted, 202 = accepted with warnings, 303 from clearance = clearance is switched off (report instead),
 * 400 = rejected with the authority's messages (Detailed Technical Guidelines, questions and answers).
 *
 * NOTHING of a request or of a certificate answer is written to a log by this class, and no exception it lets out
 * carries a credential: a failure is returned as a short text with the HTTP status only.
 */
class ZatcaClient
{
    public const VERSION = 'V2';

    /** @return array{ok:bool, http:int, requestId:?string, token:?string, secret:?string, disposition:?string, error:?string} */
    public function complianceCertificate(string $environment, string $csrPem, string $otp): array
    {
        return $this->certificateAnswer(fn () => $this->request($environment, null)->withHeaders(['OTP' => $otp])->post($this->url($environment, 'compliance'), ['csr' => base64_encode($csrPem)]));
    }

    /** @return array{ok:bool, http:int, requestId:?string, token:?string, secret:?string, disposition:?string, error:?string} */
    public function productionCertificate(string $environment, string $token, string $secret, string $complianceRequestId): array
    {
        return $this->certificateAnswer(fn () => $this->request($environment, [$token, $secret])->post($this->url($environment, 'production/csids'), ['compliance_request_id' => $complianceRequestId]));
    }

    /** @return array{ok:bool, http:int, requestId:?string, token:?string, secret:?string, disposition:?string, error:?string} */
    public function renewProductionCertificate(string $environment, string $token, string $secret, string $csrPem, string $otp): array
    {
        return $this->certificateAnswer(fn () => $this->request($environment, [$token, $secret])->withHeaders(['OTP' => $otp])->patch($this->url($environment, 'production/csids'), ['csr' => base64_encode($csrPem)]));
    }

    /**
     * One document to one of the three document services.
     *
     * @param  'compliance'|'reporting'|'clearance'  $api
     * @return array{api:string, http:int, outcome:string, status:?string, messages:array<int, array{type:string, code:?string, category:?string, message:string}>,
     *               clearedInvoice:?string, error:?string}
     *               outcome: accepted | warnings | rejected | clearance_off | unauthorized | retry
     */
    public function submit(string $api, string $environment, string $token, string $secret, string $uuid, string $hash, string $xml): array
    {
        $path = ['compliance' => 'compliance/invoices', 'reporting' => 'invoices/reporting/single', 'clearance' => 'invoices/clearance/single'][$api];
        $out = ['api' => $api, 'http' => 0, 'outcome' => 'retry', 'status' => null, 'messages' => [], 'clearedInvoice' => null, 'error' => null];
        try {
            $request = $this->request($environment, [$token, $secret])->withHeaders(['Accept-Language' => 'en']);
            if ($api !== 'compliance') {
                $request = $request->withHeaders(['Clearance-Status' => $api === 'clearance' ? '1' : '0']);
            }
            $response = $request->post($this->url($environment, $path), ['invoiceHash' => $hash, 'uuid' => $uuid, 'invoice' => base64_encode($xml)]);
        } catch (ConnectionException) {
            return ['error' => 'no connection to the authority\'s service'] + $out;
        } catch (Throwable $e) {
            return ['error' => 'the request could not be sent ('.class_basename($e).')'] + $out;
        }
        $body = $this->json($response);
        $out['http'] = $response->status();
        $out['messages'] = self::messages($body);
        $out['status'] = self::text($body['clearanceStatus'] ?? $body['reportingStatus'] ?? ($body['validationResults']['status'] ?? null));
        $out['clearedInvoice'] = is_string($body['clearedInvoice'] ?? null) && $body['clearedInvoice'] !== '' ? $body['clearedInvoice'] : null;
        $errors = count(array_filter($out['messages'], fn (array $m) => $m['type'] === 'ERROR'));
        $warnings = count(array_filter($out['messages'], fn (array $m) => $m['type'] === 'WARNING'));
        $refused = in_array(strtoupper((string) $out['status']), ['NOT_REPORTED', 'NOT_CLEARED', 'ERROR'], true);
        $out['outcome'] = match (true) {
            $response->status() === 303 => 'clearance_off',
            in_array($response->status(), [401, 403], true) => 'unauthorized',
            in_array($response->status(), [200, 202], true) && ! $refused && $errors === 0 => $response->status() === 202 || $warnings > 0 ? 'warnings' : 'accepted',
            // a refusal the authority explains (it lists what is wrong) is final for this document
            in_array($response->status(), [200, 202, 400, 406, 409, 413, 422], true) && ($errors > 0 || $refused) => 'rejected',
            $response->status() === 400 || $response->status() === 422 => 'rejected',
            default => 'retry', // 408, 429, 5xx, anything not understood: the document waits and is sent again
        };
        if ($out['outcome'] !== 'accepted' && $out['outcome'] !== 'warnings' && ! $out['messages']) {
            $out['error'] = 'HTTP '.$response->status().(self::text($body['message'] ?? null) ? ': '.mb_substr((string) self::text($body['message']), 0, 300) : '');
        }

        return $out;
    }

    public function url(string $environment, string $path): string
    {
        $gateway = rtrim((string) config('tax.zatca.gateway', Codes::GATEWAY), '/');

        return $gateway.'/'.Codes::ENVIRONMENTS[$environment]['path'].'/'.ltrim($path, '/');
    }

    /** @param array{0:string, 1:string}|null $basic */
    private function request(string $environment, ?array $basic): PendingRequest
    {
        if (! isset(Codes::ENVIRONMENTS[$environment])) {
            throw new \InvalidArgumentException("unknown environment: {$environment}");
        }
        $request = Http::timeout((int) config('tax.zatca.timeout', 25))->connectTimeout(10)->acceptJson()->asJson()->withoutRedirecting()
            ->withHeaders(['Accept-Version' => self::VERSION]);

        return $basic ? $request->withBasicAuth($basic[0], $basic[1]) : $request;
    }

    /** @param callable(): Response $call */
    private function certificateAnswer(callable $call): array
    {
        $out = ['ok' => false, 'http' => 0, 'requestId' => null, 'token' => null, 'secret' => null, 'disposition' => null, 'error' => null];
        try {
            $response = $call();
        } catch (ConnectionException) {
            return ['error' => 'no connection to the authority\'s service'] + $out;
        } catch (Throwable $e) {
            return ['error' => 'the request could not be sent ('.class_basename($e).')'] + $out;
        }
        $body = $this->json($response);
        $out['http'] = $response->status();
        $out['disposition'] = self::text($body['dispositionMessage'] ?? null);
        $token = self::text($body['binarySecurityToken'] ?? null);
        $secret = self::text($body['secret'] ?? null);
        // the request id is a long number: kept as text, never as a float
        $requestId = isset($body['requestID']) && (is_string($body['requestID']) || is_int($body['requestID'])) ? (string) $body['requestID'] : null;
        if ($response->successful() && $token && $secret) {
            return ['ok' => true, 'requestId' => $requestId, 'token' => $token, 'secret' => $secret] + $out;
        }
        $messages = self::messages($body);
        $said = $messages ? implode(' · ', array_map(fn (array $m) => trim(($m['code'] ? $m['code'].': ' : '').$m['message']), array_slice($messages, 0, 3)))
            : (self::text($body['message'] ?? $body['error'] ?? null) ?? ($out['disposition'] ?? ''));
        $out['error'] = trim('HTTP '.$response->status().($said !== '' ? ': '.mb_substr($said, 0, 400) : ''));

        return $out;
    }

    private function json(Response $response): array
    {
        // big numbers (the request id) stay text
        $body = json_decode($response->body(), true, 64, JSON_BIGINT_AS_STRING);

        return is_array($body) ? $body : [];
    }

    /**
     * The authority's messages, whatever part of the answer they stand in.
     *
     * @return array<int, array{type:string, code:?string, category:?string, message:string}>
     */
    public static function messages(array $body): array
    {
        $out = [];
        $results = is_array($body['validationResults'] ?? null) ? $body['validationResults'] : $body;
        foreach (['errorMessages' => 'ERROR', 'warningMessages' => 'WARNING', 'infoMessages' => 'INFO'] as $key => $type) {
            foreach ((array) ($results[$key] ?? []) as $m) {
                if (is_array($m)) {
                    $out[] = ['type' => strtoupper((string) (self::text($m['type'] ?? null) ?? $type)), 'code' => self::text($m['code'] ?? null), 'category' => self::text($m['category'] ?? null),
                        'message' => mb_substr((string) (self::text($m['message'] ?? null) ?? ''), 0, 1000)];
                } elseif (is_string($m) && trim($m) !== '') {
                    $out[] = ['type' => $type, 'code' => null, 'category' => null, 'message' => mb_substr(trim($m), 0, 1000)];
                }
            }
        }
        foreach ((array) ($body['errors'] ?? []) as $m) {
            if (is_array($m)) {
                $out[] = ['type' => 'ERROR', 'code' => self::text($m['code'] ?? null), 'category' => self::text($m['category'] ?? null), 'message' => mb_substr((string) (self::text($m['message'] ?? null) ?? ''), 0, 1000)];
            } elseif (is_string($m) && trim($m) !== '') {
                $out[] = ['type' => 'ERROR', 'code' => null, 'category' => null, 'message' => mb_substr(trim($m), 0, 1000)];
            }
        }

        return $out;
    }

    private static function text(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : (is_int($v) ? (string) $v : null);
    }
}
