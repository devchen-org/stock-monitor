<?php

declare(strict_types=1);

final class AppSettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(): ?array
    {
        $statement = $this->pdo->query('SELECT * FROM app_settings WHERE id = 1 LIMIT 1');
        $settings = $statement->fetch();
        if ($settings === false) {
            return null;
        }

        return $settings;
    }

    public function getAuthSettings(): array
    {
        $settings = $this->get();

        return [
            'password_hash' => (string) ($settings['login_password_hash'] ?? ''),
            'force_password_change' => !empty($settings['login_force_password_change']),
            'password_updated_at' => (string) ($settings['login_password_updated_at'] ?? ''),
        ];
    }

    public function saveDisplaySettings(
        int $quoteRefreshSeconds,
        bool $quoteRefreshOnlyTradingHours,
        int $calculatorDefaultLotSize,
        float $positionAlertGainPercent,
        float $positionAlertLossPercent
    ): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_settings
             SET quote_refresh_seconds = :quote_refresh_seconds,
                 quote_refresh_only_trading_hours = :quote_refresh_only_trading_hours,
                 calculator_default_lot_size = :calculator_default_lot_size,
                 position_alert_gain_percent = :position_alert_gain_percent,
                 position_alert_loss_percent = :position_alert_loss_percent,
                 updated_at = :updated_at
             WHERE id = 1'
        );
        $statement->execute([
            ':quote_refresh_seconds' => $quoteRefreshSeconds,
            ':quote_refresh_only_trading_hours' => $quoteRefreshOnlyTradingHours ? 1 : 0,
            ':calculator_default_lot_size' => $calculatorDefaultLotSize,
            ':position_alert_gain_percent' => $positionAlertGainPercent,
            ':position_alert_loss_percent' => $positionAlertLossPercent,
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updatePassword(string $hash, bool $forcePasswordChange = false): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_settings
             SET login_password_hash = :login_password_hash,
                 login_force_password_change = :login_force_password_change,
                 login_password_updated_at = :login_password_updated_at,
                 updated_at = :updated_at
             WHERE id = 1'
        );
        $statement->execute([
            ':login_password_hash' => $hash,
            ':login_force_password_change' => $forcePasswordChange ? 1 : 0,
            ':login_password_updated_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function setForcePasswordChange(bool $value): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_settings
             SET login_force_password_change = :login_force_password_change,
                 updated_at = :updated_at
             WHERE id = 1'
        );
        $statement->execute([
            ':login_force_password_change' => $value ? 1 : 0,
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
