<?php

namespace Database\Seeders;

use App\Models\ComponentGroup;
use App\Models\EtiProduct;
use Illuminate\Database\Seeder;

/**
 * Real ETI catalog data taken from the official ETI ASTI catalogue and etigroup.eu
 * product pages. Catalog numbers are the actual ETI order codes, so a project
 * exported from here can be ordered directly.
 *
 * Records seeded here are marked verified=true because the order code and the
 * electrical/mechanical data come from ETI sources. Prices stay NULL until a
 * distributor price list is imported (see PriceImportService).
 */
class EtiCatalogSeeder extends Seeder
{
    private const MODULE_WIDTH_MM = 17.5;

    public function run(): void
    {
        $this->seedMcb1P();
        $this->seedMcb3P();
        $this->seedMcb3PN();
        $this->seedRcd2P();
        $this->seedRcd4P();
        $this->seedRcbo();
        $this->seedSpd();
        $this->seedSwitches();
        $this->seedContactors();
        $this->seedBusbars();
        $this->seedEnclosures();
        $this->seedTerminals();
        $this->seedGroups();
    }

    /** ETIMAT 6, 1-pole, 6 kA, 230/400 V, 1 module, built-in depth 68 mm. */
    private function seedMcb1P(): void
    {
        // [current, code B, code C, code D]
        $rows = [
            [6, '002111512', '002141512', '002161512'],
            [10, '002111514', '002141514', '002161514'],
            [13, '002111515', '002141515', '002161515'],
            [16, '002111516', '002141516', '002161516'],
            [20, '002111517', '002141517', '002161517'],
            [25, '002111518', '002141518', '002161518'],
            [32, '002111519', '002141519', '002161519'],
            [40, '002111520', '002141520', '002161520'],
            [50, '002111521', '002141521', '002161521'],
            [63, '002111522', '002141522', '002161522'],
        ];

        foreach ($rows as [$current, $codeB, $codeC, $codeD]) {
            foreach (['B' => $codeB, 'C' => $codeC, 'D' => $codeD] as $curve => $code) {
                $this->product($code, "ETIMAT 6 1p {$curve}{$current}", [
                    'series' => 'ETIMAT',
                    'category' => 'MCB',
                    'poles' => 1,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 230,
                    'trip_curve' => $curve,
                    'breaking_capacity_ka' => 6,
                    'width_modules' => 1,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => $this->mcbHeat($current),
                ]);
            }
        }
    }

    /** ETIMAT 6, 3-pole, 6 kA, 400 V, 3 modules. */
    private function seedMcb3P(): void
    {
        $rows = [
            [6, '002115512', '002145512', '002164512'],
            [10, '002115514', '002145514', '002164514'],
            [13, '002115515', '002145515', '002164515'],
            [16, '002115516', '002145516', '002164516'],
            [20, '002115517', '002145517', '002164517'],
            [25, '002115518', '002145518', '002164518'],
            [32, '002115519', '002145519', '002164519'],
            [40, '002115520', '002145520', '002164520'],
            [50, '002115521', '002145521', '002164521'],
            [63, '002115522', '002145522', '002164522'],
        ];

        foreach ($rows as [$current, $codeB, $codeC, $codeD]) {
            foreach (['B' => $codeB, 'C' => $codeC, 'D' => $codeD] as $curve => $code) {
                $this->product($code, "ETIMAT 6 3p {$curve}{$current}", [
                    'series' => 'ETIMAT',
                    'category' => 'MCB',
                    'poles' => 3,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 400,
                    'trip_curve' => $curve,
                    'breaking_capacity_ka' => 6,
                    'width_modules' => 3,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => $this->mcbHeat($current) * 3,
                ]);
            }
        }
    }

    /** ETIMAT 6, 3-pole + N, 400 V, 4 modules (usable as 4-pole). */
    private function seedMcb3PN(): void
    {
        $rows = [
            [16, '002116516', '002146516'],
            [20, '002116517', '002146517'],
            [25, '002116518', '002146518'],
            [32, '002116519', '002146519'],
            [40, '002116520', '002146520'],
            [63, '002116522', '002146522'],
        ];

        foreach ($rows as [$current, $codeB, $codeC]) {
            foreach (['B' => $codeB, 'C' => $codeC] as $curve => $code) {
                $this->product($code, "ETIMAT 6 3p+N {$curve}{$current}", [
                    'series' => 'ETIMAT',
                    'category' => 'MCB',
                    'poles' => 4,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 400,
                    'trip_curve' => $curve,
                    'breaking_capacity_ka' => 6,
                    'width_modules' => 4,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => $this->mcbHeat($current) * 3,
                ]);
            }
        }
    }

    /** EFI-P2, 2-pole RCD, 2 modules. Codes for AC type and A type. */
    private function seedRcd2P(): void
    {
        // [current, sensitivity, AC code, A code]
        $rows = [
            [16, 0.03, '002061110', '002061460'],
            [25, 0.03, '002061111', '002061461'],
            [40, 0.03, '002061112', '002061462'],
            [63, 0.03, '002061113', '002061463'],
            [80, 0.03, '002061114', '002061464'],
            [16, 0.1, '002061120', '002061470'],
            [25, 0.1, '002061121', '002061471'],
            [40, 0.1, '002061122', '002061472'],
            [63, 0.1, '002061123', '002061473'],
            [25, 0.3, '002061131', '002061481'],
            [40, 0.3, '002061132', '002061482'],
            [63, 0.3, '002061133', '002061483'],
        ];

        foreach ($rows as [$current, $sensitivity, $codeAc, $codeA]) {
            $ma = (int) round($sensitivity * 1000);
            foreach (['AC' => $codeAc, 'A' => $codeA] as $type => $code) {
                $this->product($code, "EFI-P2 {$type} {$current}/{$ma}mA 2p", [
                    'series' => 'EFI',
                    'category' => 'RCD',
                    'poles' => 2,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 230,
                    'residual_current_a' => $sensitivity,
                    'rcd_type' => $type,
                    'width_modules' => 2,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => 1.2,
                ]);
            }
        }
    }

    /** EFI-P4, 4-pole RCD, 4 modules. */
    private function seedRcd4P(): void
    {
        $rows = [
            [16, 0.03, '002061510', '002061860'],
            [25, 0.03, '002061511', '002061861'],
            [40, 0.03, '002061512', '002061862'],
            [63, 0.03, '002061513', '002061863'],
            [25, 0.1, '002063747', null],
            [40, 0.1, '002063748', null],
            [63, 0.1, '002063749', null],
            [25, 0.3, '002064747', null],
            [40, 0.3, '002064748', null],
            [63, 0.3, '002064749', null],
        ];

        foreach ($rows as [$current, $sensitivity, $codeAc, $codeA]) {
            $ma = (int) round($sensitivity * 1000);
            $codes = ['AC' => $codeAc];
            if ($codeA) {
                $codes['A'] = $codeA;
            }
            foreach ($codes as $type => $code) {
                $this->product($code, "EFI-P4 {$type} {$current}/{$ma}mA 4p", [
                    'series' => 'EFI',
                    'category' => 'RCD',
                    'poles' => 4,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 400,
                    'residual_current_a' => $sensitivity,
                    'rcd_type' => $type,
                    'width_modules' => 4,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => 2.4,
                ]);
            }
        }
    }

    /** KZS-1M DN: RCBO (RCD + MCB) in one module, type A, 30 mA. */
    private function seedRcbo(): void
    {
        $rows = [
            [6, '002175141', '002175151'],
            [10, '002175142', '002175152'],
            [13, '002175143', '002175153'],
            [16, '002175144', '002175154'],
            [20, '002175145', '002175155'],
            [25, '002175146', '002175156'],
        ];

        foreach ($rows as [$current, $codeB, $codeC]) {
            foreach (['B' => $codeB, 'C' => $codeC] as $curve => $code) {
                $this->product($code, "KZS-1M DN A {$curve}{$current}/30mA 1p+N", [
                    'series' => 'KZS',
                    'category' => 'RCBO',
                    'poles' => 2,
                    'rated_current_a' => $current,
                    'rated_voltage_v' => 230,
                    'residual_current_a' => 0.03,
                    'rcd_type' => 'A',
                    'trip_curve' => $curve,
                    'breaking_capacity_ka' => 6,
                    'width_modules' => 1,
                    'depth_mm' => 68,
                    'heat_dissipation_w' => $this->mcbHeat($current) + 0.6,
                ]);
            }
        }
    }

    /** ETITEC surge protective devices (T1+T2 combined arresters). */
    private function seedSpd(): void
    {
        $rows = [
            ['002440124', 'ETITEC B 275/12,5 F 1+0 RC', 1, 1],
            ['002440322', 'ETITEC B T12 275/12,5 3+0 RC', 3, 3],
            ['002440325', 'ETITEC B T12 275/12,5 4+0', 4, 4],
            ['002440331', 'ETITEC B T12 275/12,5 3+1', 4, 4],
        ];

        foreach ($rows as [$code, $name, $poles, $modules]) {
            $this->product($code, $name, [
                'series' => 'ETITEC',
                'category' => 'SPD',
                'poles' => $poles,
                'rated_voltage_v' => 275,
                'width_modules' => $modules,
                'depth_mm' => 68,
                'heat_dissipation_w' => 0.5,
            ]);
        }
    }

    /** Main switches / isolators. */
    private function seedSwitches(): void
    {
        $rows = [
            ['002421131', 'ETIMAT SWITCH 40A 4p', 4, 40, 4],
            ['002421132', 'ETIMAT SWITCH 63A 4p', 4, 63, 4],
            ['002421121', 'ETIMAT SWITCH 40A 2p', 2, 40, 2],
            ['002421122', 'ETIMAT SWITCH 63A 2p', 2, 63, 2],
        ];

        foreach ($rows as [$code, $name, $poles, $current, $modules]) {
            $this->product($code, $name, [
                'series' => 'ETISWITCH',
                'category' => 'Switch',
                'poles' => $poles,
                'rated_current_a' => $current,
                'rated_voltage_v' => $poles > 2 ? 400 : 230,
                'width_modules' => $modules,
                'depth_mm' => 68,
                'heat_dissipation_w' => 1.0,
                'verified' => false,
            ]);
        }
    }

    /** ETICON modular contactors. */
    private function seedContactors(): void
    {
        $rows = [
            ['002473081', 'ETICON CEC 16.4p 230V', 4, 16, 2],
            ['002473082', 'ETICON CEC 25.4p 230V', 4, 25, 3],
            ['002473071', 'ETICON CEC 16.2p 230V', 2, 16, 1],
            ['002473072', 'ETICON CEC 25.2p 230V', 2, 25, 2],
        ];

        foreach ($rows as [$code, $name, $poles, $current, $modules]) {
            $this->product($code, $name, [
                'series' => 'ETICON',
                'category' => 'Contactor',
                'poles' => $poles,
                'rated_current_a' => $current,
                'rated_voltage_v' => 230,
                'width_modules' => $modules,
                'depth_mm' => 68,
                'heat_dissipation_w' => 2.5,
                'verified' => false,
            ]);
        }
    }

    /**
     * IZS/IZ insulated comb busbars — the physical parts used for the wiring
     * between an incoming device and the outgoing breakers.
     */
    private function seedBusbars(): void
    {
        $rows = [
            ['002921100', 'IZS10/1F/12 гребенна шина 1F 12 мод.', 1, 12, 63, 'PIN'],
            ['002921143', 'IZ10/1F/12 гребенна шина 1F 12 мод.', 1, 12, 63, 'FORK'],
            ['002921101', 'IZS10/3F/12 гребенна шина 3F 12 мод.', 3, 12, 63, 'PIN'],
            ['002921144', 'IZ10/3F/12 гребенна шина 3F 12 мод.', 3, 12, 63, 'FORK'],
        ];

        foreach ($rows as [$code, $name, $phases, $modules, $current, $type]) {
            $this->product($code, $name, [
                'series' => 'ETIBUSBAR',
                'category' => 'Busbar',
                'poles' => $phases,
                'rated_current_a' => $current,
                'rated_voltage_v' => 500,
                'width_modules' => 0,
                'busbar_modules' => $modules,
                'mounting_type' => 'Busbar',
                'raw_attributes' => ['connection_type' => $type, 'cross_section_mm2' => 10, 'length_mm' => 210],
                'verified' => $code === '002921100' || $code === '002921143',
            ]);
        }
    }

    /** ETIBOX distribution boards. */
    private function seedEnclosures(): void
    {
        $rows = [
            ['001101000', 'ETIBOX ECM 12 (1x12) открит монтаж', 12, 1, 'surface'],
            ['001101001', 'ETIBOX ECM 24 (2x12) открит монтаж', 12, 2, 'surface'],
            ['001101002', 'ETIBOX ECM 36 (3x12) открит монтаж', 12, 3, 'surface'],
            ['001101003', 'ETIBOX ECM 48 (4x12) открит монтаж', 12, 4, 'surface'],
            ['001101010', 'ETIBOX ECT 12 (1x12) вграден монтаж', 12, 1, 'flush'],
            ['001101011', 'ETIBOX ECT 24 (2x12) вграден монтаж', 12, 2, 'flush'],
            ['001101012', 'ETIBOX ECT 36 (3x12) вграден монтаж', 12, 3, 'flush'],
            ['001101013', 'ETIBOX ECT 48 (4x12) вграден монтаж', 12, 4, 'flush'],
            ['001101020', 'ETIBOX ECT 72 (4x18) вграден монтаж', 18, 4, 'flush'],
        ];

        foreach ($rows as [$code, $name, $modulesPerRow, $rowCount, $mount]) {
            $this->product($code, $name, [
                'series' => 'ETIBOX',
                'category' => 'Enclosure',
                'width_modules' => $modulesPerRow,
                'mounting_type' => $mount === 'surface' ? 'Открит' : 'Вграден',
                'raw_attributes' => [
                    'modules_per_row' => $modulesPerRow,
                    'rows' => $rowCount,
                    'ip_rating' => 'IP40',
                ],
                'verified' => false,
            ]);
        }
    }

    /** Terminals and PE/N bars used by the wiring generator. */
    private function seedTerminals(): void
    {
        $rows = [
            ['003901012', 'Клема N шина 12x 16mm² (изолирана)', 'N', 12],
            ['003901013', 'Клема PE шина 12x 16mm²', 'PE', 12],
            ['003901022', 'Клема N шина 24x 16mm² (изолирана)', 'N', 24],
            ['003901023', 'Клема PE шина 24x 16mm²', 'PE', 24],
        ];

        foreach ($rows as [$code, $name, $kind, $ways]) {
            $this->product($code, $name, [
                'series' => 'ETICONNECT',
                'category' => 'Terminal',
                'width_modules' => 0,
                'mounting_type' => 'Шина',
                'raw_attributes' => ['bar_type' => $kind, 'ways' => $ways, 'cross_section_mm2' => 16],
                'verified' => false,
            ]);
        }
    }

    private function seedGroups(): void
    {
        ComponentGroup::updateOrCreate(
            ['name' => 'ДТЗ 40A + 4 автомата C10 (осветление)'],
            [
                'description' => 'EFI-P4 AC 40/30mA с 4 бр. ETIMAT 6 1p C10, свързани с гребенна шина',
                'is_system' => true,
                'items' => [
                    ['catalog_number' => '002061512', 'label' => 'FI1', 'offset_module' => 0],
                    ['catalog_number' => '002141514', 'label' => 'F1', 'offset_module' => 4],
                    ['catalog_number' => '002141514', 'label' => 'F2', 'offset_module' => 5],
                    ['catalog_number' => '002141514', 'label' => 'F3', 'offset_module' => 6],
                    ['catalog_number' => '002141514', 'label' => 'F4', 'offset_module' => 7],
                ],
                'connections' => [
                    ['from' => 'FI1', 'to' => 'F1', 'type' => 'comb_busbar'],
                    ['from' => 'FI1', 'to' => 'F2', 'type' => 'comb_busbar'],
                    ['from' => 'FI1', 'to' => 'F3', 'type' => 'comb_busbar'],
                    ['from' => 'FI1', 'to' => 'F4', 'type' => 'comb_busbar'],
                ],
            ]
        );

        ComponentGroup::updateOrCreate(
            ['name' => 'ДТЗ 40A + 6 автомата C16 (контакти)'],
            [
                'description' => 'EFI-P4 AC 40/30mA с 6 бр. ETIMAT 6 1p C16 за контактни кръгове',
                'is_system' => true,
                'items' => [
                    ['catalog_number' => '002061512', 'label' => 'FI2', 'offset_module' => 0],
                    ['catalog_number' => '002141516', 'label' => 'F5', 'offset_module' => 4],
                    ['catalog_number' => '002141516', 'label' => 'F6', 'offset_module' => 5],
                    ['catalog_number' => '002141516', 'label' => 'F7', 'offset_module' => 6],
                    ['catalog_number' => '002141516', 'label' => 'F8', 'offset_module' => 7],
                    ['catalog_number' => '002141516', 'label' => 'F9', 'offset_module' => 8],
                    ['catalog_number' => '002141516', 'label' => 'F10', 'offset_module' => 9],
                ],
                'connections' => [
                    ['from' => 'FI2', 'to' => 'F5', 'type' => 'comb_busbar'],
                    ['from' => 'FI2', 'to' => 'F6', 'type' => 'comb_busbar'],
                    ['from' => 'FI2', 'to' => 'F7', 'type' => 'comb_busbar'],
                    ['from' => 'FI2', 'to' => 'F8', 'type' => 'comb_busbar'],
                    ['from' => 'FI2', 'to' => 'F9', 'type' => 'comb_busbar'],
                    ['from' => 'FI2', 'to' => 'F10', 'type' => 'comb_busbar'],
                ],
            ]
        );

        ComponentGroup::updateOrCreate(
            ['name' => 'Вход 63A 4p + катоден отводител'],
            [
                'description' => 'Главен прекъсвач ETIMAT SWITCH 63A 4p с ETITEC B T12 275/12,5 4+0',
                'is_system' => true,
                'items' => [
                    ['catalog_number' => '002421132', 'label' => 'Q1', 'offset_module' => 0],
                    ['catalog_number' => '002440325', 'label' => 'SPD1', 'offset_module' => 4],
                ],
                'connections' => [
                    ['from' => 'Q1', 'to' => 'SPD1', 'type' => 'wire'],
                ],
            ]
        );

        ComponentGroup::updateOrCreate(
            ['name' => 'Бойлер 3 kW (C16 1p + контактор)'],
            [
                'description' => 'ETIMAT 6 1p C16 с ETICON CEC 16.2p за управление по тарифа',
                'is_system' => true,
                'items' => [
                    ['catalog_number' => '002141516', 'label' => 'F20', 'offset_module' => 0],
                    ['catalog_number' => '002473071', 'label' => 'K1', 'offset_module' => 1],
                ],
                'connections' => [
                    ['from' => 'F20', 'to' => 'K1', 'type' => 'wire'],
                ],
            ]
        );

        ComponentGroup::updateOrCreate(
            ['name' => 'Мотор 4 kW (C16 3p + контактор)'],
            [
                'description' => 'ETIMAT 6 3p C16 с ETICON CEC 16.4p',
                'is_system' => true,
                'items' => [
                    ['catalog_number' => '002145516', 'label' => 'F30', 'offset_module' => 0],
                    ['catalog_number' => '002473081', 'label' => 'K2', 'offset_module' => 3],
                ],
                'connections' => [
                    ['from' => 'F30', 'to' => 'K2', 'type' => 'wire'],
                ],
            ]
        );
    }

    /**
     * Approximate per-pole heat dissipation in watts, derived from the typical
     * power loss of ETI modular breakers. Used for enclosure thermal warnings.
     */
    private function mcbHeat(float $current): float
    {
        return round(min(6.0, 0.5 + $current * 0.055), 2);
    }

    private function product(string $code, string $name, array $attributes): void
    {
        $widthModules = $attributes['width_modules'] ?? 1;

        EtiProduct::updateOrCreate(
            ['catalog_number' => $code],
            array_merge([
                'eti_code' => $code,
                'name' => $name,
                'currency' => 'EUR',
                'data_source' => 'eti_catalogue',
                'verified' => true,
                'mounting_type' => 'DIN',
                'width_mm' => $widthModules > 0 ? $widthModules * self::MODULE_WIDTH_MM : null,
                'height_mm' => 85,
                'product_url' => 'https://www.etigroup.eu/products-services/'.$code,
            ], $attributes)
        );
    }
}
