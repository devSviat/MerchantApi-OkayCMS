<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Backend\Controllers\MerchantApiAdmin;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use PHPUnit\Framework\TestCase;

/**
 * Реєстрація проєкту дає вписаному email роль у самому Merchant Center. Після того як проєкт ключа
 * зареєстровано, форма зникає й сервер її не приймає: інакше менеджер із правом модуля міг би видати
 * доступ до акаунта комусь іще.
 */
class RegistrationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function testRegistrationIsOpenOnlyForUnregisteredProjectOfCurrentKey(): void
    {
        $this->assertTrue(ModuleSettings::registrationOpen('shop-feed', null));
        $this->assertFalse(ModuleSettings::registrationOpen('shop-feed', 'shop-feed'));
        $this->assertTrue(ModuleSettings::registrationOpen('new-project', 'shop-feed'), 'ключ з іншого проєкту треба зареєструвати знову');
        $this->assertFalse(ModuleSettings::registrationOpen(null, null), 'без ключа реєструвати нічого');
    }

    public function testRegisteredProjectIsStored(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $this->assertNull($settings->registeredProject());

        $settings->setRegisteredProject('shop-feed');

        $this->assertSame('shop-feed', $settings->registeredProject());
    }

    public function testRegistrationFormIsHiddenOnceRegistered(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../Okay/Modules/Sviat/MerchantApi/Backend/design/html/merchant_api.tpl');

        $open = strpos($template, '{if $ma_registration_open}');
        $form = strpos($template, 'name="action" value="register_gcp"');
        $this->assertNotFalse($open);
        $this->assertGreaterThan($open, $form);
        $this->assertLessThan(strpos($template, '{elseif $ma_registered_project}', $open), $form);
        $this->assertSame('access', MerchantApiAdmin::sectionOf('register_gcp'));
    }

    private function settingsStub(): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn($name) => $this->values[$name] ?? null);
        $settings->method('set')->willReturnCallback(function ($name, $value) use ($settings) {
            $this->values[$name] = $value;

            return $settings;
        });

        return $settings;
    }
}
