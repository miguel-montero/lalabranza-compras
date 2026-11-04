<?php
declare(strict_types=1);

namespace KT\Tests;

use KT\Http\HttpError;
use KT\Settings;

final class SettingsTest extends DbTestCase
{
    public function test_settings_defaults(): void
    {
        $this->assertSame('90', Settings::get('recent_days'));
        $this->assertSame('0.19', Settings::get('iva_rate'));
        $this->assertSame('America/Santiago', Settings::all()['timezone']);
    }

    public function test_set_and_read(): void
    {
        Settings::set(['iva_rate' => 0.2, 'recent_days' => 30, 'alert_threshold_pct' => '12.5']);
        $this->assertSame('0.2', Settings::get('iva_rate'));
        $this->assertSame('30', Settings::get('recent_days'));
        $this->assertSame('12.5', Settings::get('alert_threshold_pct'));
    }

    /** @dataProvider bad */
    public function test_settings_reject_out_of_range(array $kv): void
    {
        try {
            Settings::set($kv);
            $this->fail('expected 422');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_setting', $e->errorCode);
        }
    }

    public static function bad(): array
    {
        return [
            [['iva_rate' => 1.5]],
            [['iva_rate' => -0.1]],
            [['alert_threshold_pct' => 101]],
            [['recent_days' => 0]],
            [['recent_days' => 3651]],
            [['recent_days' => 'abc']],
            [['nope' => 1]],
        ];
    }

    public function test_invalid_set_is_atomic(): void
    {
        try {
            Settings::set(['recent_days' => 10, 'iva_rate' => 5]);
        } catch (HttpError) {
        }
        $this->assertSame('90', Settings::get('recent_days'));
    }
}
