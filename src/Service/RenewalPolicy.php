<?php

namespace App\Service;

use App\Certificate\CertificateStatus as Status;

final class RenewalPolicy
{
    public static function decide(VerificationReport $report, VerificationReport $source, int $days, bool $repair): RenewCheckResult
    {
        $remaining = $report->certificateInfo?->daysUntilExpiration();
        $local = $report->status('local');
        $public = $report->status('public');
        $sourceUsable = $source->scopeSuccessful('local') && ($source->certificateInfo?->daysUntilExpiration() ?? 0) > $days;
        $localUsable = $report->scopeSuccessful('local') && ($remaining ?? 0) > $days;
        $action = false;
        $status = 'valid';
        if ($repair && ($sourceUsable || $localUsable) && !$report->successful()
            && !\in_array($public, [Status::CONNECTION_ERROR, Status::REVOCATION_UNKNOWN], true)) {
            $action = true;
            $status = 'reinstall';
        } elseif (\in_array($local, [Status::REVOKED, Status::EXPIRED, Status::CERTIFICATE_MISSING], true)
            || ($report->scopeSuccessful('local') && null !== $remaining && $remaining <= $days)) {
            $action = true;
            $status = $local->value;
        } elseif (!$report->successful()) {
            $status = ($report->scopeSuccessful('local') ? $public : $local)->value;
        }

        return new RenewCheckResult($action, $status, $report->summary().'; Aktion: '.($action ? $status : 'keine'), $remaining, $report);
    }
}
