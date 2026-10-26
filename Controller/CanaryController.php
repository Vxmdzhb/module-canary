<?php
declare(strict_types=1);

namespace AstrX\Canary\Controller;

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\TemplateVars;
use PDO;
use AstrX\Kernel\AbstractController;

/**
 * Public warrant canary page (/canary).
 *
 * Shows the operator's current, dated statement exactly as pasted. The
 * operator signs it offline (e.g. PGP or minisign) and pastes the signed block
 * into the admin editor, so no signing key lives on the server: a seized
 * server can't produce a fresh canary. Once the statement is older than the
 * configured interval the page shows a clear STALE warning, because a canary
 * that stops being refreshed is the signal. 404 when nothing is published.
 *
 * Stored in the `site_config` key-value table (canary_* keys); no new table,
 * no crypto on the server.
 */
final class CanaryController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly TemplateVars $ctx,
        private readonly PDO                     $pdo,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->ctx->set('canary_heading', $this->t->t('canary.heading'));

        $statement = trim($this->cfg('canary_statement'));
        $enabled   = $this->cfg('canary_enabled') === '1';

        if (!$enabled || $statement === '') {
            http_response_code(404);
            $this->ctx->set('published', false);
            $this->ctx->set('canary_not_published', $this->t->t('canary.not_published'));
            return $this->ok();
        }

        $updatedAt   = self::int($this->cfg('canary_updated_at'), 0);
        $intervalDays = max(1, self::int($this->cfg('canary_interval_days'), 14));
        $stale       = $updatedAt > 0 && (time() - $updatedAt) > ($intervalDays * 86400);

        $this->ctx->set('published', true);
        $this->ctx->set('statement', $statement);
        $this->ctx->set('canary_intro', $this->t->t('canary.intro'));
        $this->ctx->set('canary_last_attested_label', $this->t->t('canary.last_attested'));
        $this->ctx->set('attested_date', $updatedAt > 0 ? gmdate('Y-m-d H:i', $updatedAt) . ' UTC' : '—');
        $this->ctx->set('stale', $stale);
        $this->ctx->set('canary_stale_warning', $this->t->t('canary.stale_warning'));

        return $this->ok();
    }

    private function cfg(string $key): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM `site_config` WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return ''; }
            /** @var array<string,mixed> $row */
            return is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }
}
