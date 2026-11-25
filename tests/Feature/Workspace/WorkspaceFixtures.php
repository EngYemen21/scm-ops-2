<?php

namespace Tests\Feature\Workspace;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * What the tests of «الدعم والمشاريع والمستندات» share. The section's tables are shared by every story of the run, so
 * each story works in a team, a project or a folder of its own. Every value here is invented.
 *
 * The seeded people: admin holds everything; sales holds ticket.manage; gm holds project.manage; wm, inv, proc, disp
 * and finance only open the section (page.workspace); worker cannot open it.
 */
trait WorkspaceFixtures
{
    private static int $wsSeq = 0;

    /** A short unique tag for names (two calls in one millisecond still differ). */
    protected static function tag(): string
    {
        return self::uid().'-'.(++self::$wsSeq);
    }

    /** Nothing in these tests may reach another system: a stray HTTP call fails the test instead of leaving the machine. */
    protected function sealNetwork(): void
    {
        Http::preventStrayRequests();
    }

    /** The section follows Odoo (records that came from Odoo are Odoo's) or is handed over to OPS. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.workspace' => $on]);
    }

    protected function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    protected function ws(string $as, string $path): array
    {
        return $this->expectOk($this->getAs($as, "/api/workspace/{$path}"));
    }

    // ───────────── files as the client sends them ─────────────

    /** A real 1×1 PNG as a data URL. */
    protected static function pngFile(string $name = 'screen.png'): array
    {
        return ['fileName' => $name, 'mime' => 'image/png', 'base64' => self::proofImage()];
    }

    /** A small real PDF. */
    protected static function pdfFile(string $name = 'paper.pdf', string $text = 'one'): array
    {
        return ['fileName' => $name, 'mime' => 'application/pdf', 'base64' => base64_encode("%PDF-1.4\n% {$text}\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n")];
    }

    /** The first bytes of a Word / Excel file (a zip archive): what the type check looks at. */
    protected static function officeFile(string $name = 'contract.docx'): array
    {
        return ['fileName' => $name, 'base64' => base64_encode("PK\x03\x04\x14\x00\x06\x00".str_repeat("\x00", 40).'[Content_Types].xml')];
    }

    // ───────────── a help desk of its own ─────────────

    /**
     * A team with its own path: two open stages and one that closes, for this team only (sequence 1–3, so they come
     * before whatever else the run holds). Members are usernames; the help-desk manager (sales) creates everything.
     *
     * @return array{team:array, open:string, work:string, done:string}
     */
    protected function desk(array $team = [], array $members = ['proc']): array
    {
        $tag = self::tag();
        $t = $this->expectOk($this->postAs('sales', '/api/workspace/ticket-teams', $team + [
            'nameAr' => "فريق اختبار {$tag}", 'nameEn' => "QA desk {$tag}", 'firstResponseHours' => 4, 'resolutionHours' => 48,
            'memberKeys' => array_map(fn (string $u) => $this->userId($u), $members),
        ]));
        $stage = fn (string $ar, string $en, int $seq, bool $closing) => $this->expectOk($this->postAs('sales', '/api/workspace/ticket-stages',
            ['nameAr' => "{$ar} {$tag}", 'nameEn' => "{$en} {$tag}", 'sequence' => $seq, 'isClosing' => $closing, 'teamIds' => [$t['id']]]))['id'];

        return ['team' => $t, 'open' => $stage('جديدة', 'QA new', 1, false), 'work' => $stage('قيد العمل', 'QA working', 2, false), 'done' => $stage('محلولة', 'QA solved', 3, true)];
    }

    /** POST /api/workspace/tickets in the given desk. */
    protected function newTicket(array $desk, array $more = [], string $as = 'wm'): array
    {
        return $this->expectOk($this->postAs($as, '/api/workspace/tickets', $more + ['subject' => 'تذكرة اختبار '.self::tag(), 'teamId' => $desk['team']['id']]));
    }

    // ───────────── a project of its own ─────────────

    /** A project made by the projects manager (gm): three default stages (to do · in progress · done). @return array the project */
    protected function newProject(array $more = [], array $members = ['inv']): array
    {
        return $this->expectOk($this->postAs('gm', '/api/workspace/projects', $more + ['name' => 'مشروع اختبار '.self::tag(), 'memberKeys' => array_map(fn (string $u) => $this->userId($u), $members)]));
    }

    // ───────────── a folder of its own ─────────────

    /** A folder made by the documents manager (admin). */
    protected function newFolder(array $more = []): array
    {
        return $this->expectOk($this->postAs('admin', '/api/workspace/folders', $more + ['name' => 'مجلد اختبار '.self::tag()]));
    }

    // ───────────── a small mirror of Odoo, written straight into the copy's tables (Odoo is never called) ─────────────

    protected function emptyMirror(): void
    {
        // the copy of the real Odoo lives in the development database: these rows are only ever written in a test database
        Assert::assertStringContainsString('test', (string) DB::connection()->getDatabaseName(), 'the small mirror is built in a test database only');
        foreach (['odoo_changes', 'odoo_records', 'odoo_models', 'odoo_files'] as $table) {
            DB::table($table)->delete();
        }
    }

    /** Registers a model of the copy as fully copied. */
    protected function mirrorModel(string $model, bool $done = true): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['label' => $model, 'status' => 'active', 'full_state' => $done ? 'done' : 'running', 'has_write_date' => true, 'has_active' => true,
            'fields' => ['display_name' => 'char'], 'priority' => 1000, 'page_size' => 500]);
    }

    /** One record of the copy, as the mirror would hold it; `$change` also puts it on the feed (created | updated | archived | deleted). */
    protected function mirrorRecord(string $model, int $id, array $data, ?string $change = null, bool $deleted = false, ?string $writeDate = null): void
    {
        $now = now();
        $row = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $values = ['display_name' => is_string($data['name'] ?? null) ? mb_substr($data['name'], 0, 250) : null, 'write_date' => $writeDate ?? $now->format('Y-m-d H:i:s'),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null, 'data' => $data, 'hash' => sha1(json_encode($data)), 'changed_at' => $now, 'deleted_at' => $deleted ? $now : null];
        $row ? $row->update($values + ['version' => $row->version + 1]) : OdooRecord::create(['model' => $model, 'odoo_id' => $id, 'first_seen_at' => $now, 'version' => 1] + $values);
        if ($change) {
            OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $change, 'seen_at' => $now]);
        }
    }
}
