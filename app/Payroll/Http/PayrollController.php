<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\SummaryService;
use App\Support\AuthUser;

/** The top of the page and what its forms pick from — each caller gets the part they may see. */
class PayrollController extends Controller
{
    public function __construct(private readonly SummaryService $summary) {}

    public function summary(): array
    {
        return $this->summary->summary(AuthUser::current());
    }

    public function lookups(): array
    {
        return $this->summary->lookups(AuthUser::current());
    }
}
