<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

interface Spec
{
    /** @return string|false category string on match, false on no match */
    public function isSatisfied(object $ctx): string|false;
}

class OrSpecification implements Spec
{
    private array $specs;

    public function __construct(Spec ...$specs)
    {
        $this->specs = $specs;
    }

    public function isSatisfied(object $ctx): string|false
    {
        foreach ($this->specs as $spec) {
            $result = $spec->isSatisfied($ctx);
            if ($result !== false) return $result;
        }
        return false;
    }
}

class NewSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->went_pasif_at !== null) return false; // has pasif history → not new
        return 'new';
    }
}

class DiagnosedSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->event !== 'updated') return false;
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->prev_daftar === 'Ya') return false; // no transition
        if ($ctx->went_pasif_at === null) return false; // no pasif history
        if ($ctx->pasif_days === null || $ctx->pasif_days >= 90) return false;
        return 'diagnosed';
    }
}

class ReactivationSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->event !== 'updated') return false;
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->prev_daftar === 'Ya') return false;
        if ($ctx->went_pasif_at === null) return false;
        if ($ctx->pasif_days === null || $ctx->pasif_days < 90) return false;
        return 'reactivation';
    }
}
