<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 2px solid #d32f2f; padding-bottom: 3px; }
        .meta { color: #555; font-size: 10px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f4f4f4; font-size: 10px; }
        td.num, th.num { text-align: right; }
        .totals { margin-top: 8px; font-size: 12px; font-weight: bold; text-align: right; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 9px; }
        .verified { background: #e8f5e9; color: #2e7d32; }
        .unverified { background: #fff3e0; color: #e65100; }
        .panel-box { border: 2px solid #333; margin-top: 8px; padding: 6px; }
        .din-row { border: 1px dashed #999; margin: 4px 0; padding: 3px; min-height: 22px; }
        .module { display: inline-block; border: 1px solid #666; background: #eef; padding: 2px 4px; margin: 1px; font-size: 9px; }
        .header-band { border-bottom: 3px solid #d32f2f; padding-bottom: 8px; margin-bottom: 10px; }
        td.bad { color: #c62828; font-weight: bold; }
        .page-break { page-break-before: always; }
        .schematic { border: 1px solid #ccc; padding: 6px; text-align: center; }
        .schematic img { width: 100%; height: auto; }
    </style>
</head>
<body>
    <div class="header-band">
        <h1>{{ $project->name }}</h1>
        <div class="meta">
            @if($project->client_name) Клиент: {{ $project->client_name }} &nbsp;|&nbsp; @endif
            Версия: {{ $project->current_version }} &nbsp;|&nbsp; Генериран: {{ $generatedAt }} &nbsp;|&nbsp; ETI Panel Designer
        </div>
        @if($project->description)<div>{{ $project->description }}</div>@endif
    </div>

    <h2>Изглед на таблото</h2>
    <div class="panel-box">
        @php $rows = $design['rows'] ?? ($panel['rows'] ?? []); @endphp
        @if(!empty($rows))
            @foreach($rows as $rowIndex => $row)
                <div class="din-row">
                    <strong style="font-size:9px;">DIN {{ $rowIndex + 1 }}:</strong>
                    @foreach($design['components'] ?? [] as $component)
                        @if(($component['row'] ?? 0) == $rowIndex)
                            <span class="module">{{ $component['label'] ?? '' }} {{ $component['catalogNumber'] ?? $component['catalog_number'] ?? '' }}</span>
                        @endif
                    @endforeach
                </div>
            @endforeach
        @else
            <em>Няма разположени компоненти.</em>
        @endif
    </div>

    @if($schematic)
        <div class="page-break"></div>
        <h2>Еднолинейна схема</h2>
        <div class="schematic">
            <img src="{{ $schematic }}" alt="Еднолинейна схема">
        </div>
        <div class="page-break"></div>
    @endif

    <h2>Количествена сметка (BOM)</h2>
    <table>
        <thead>
            <tr>
                <th>Кат. номер</th>
                <th>Наименование</th>
                <th>Серия</th>
                <th class="num">Кол.</th>
                <th class="num">Ед. цена</th>
                <th class="num">Общо</th>
                <th>Данни</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bom['items'] as $item)
                <tr>
                    <td>{{ $item['catalog_number'] }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['series'] }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format($item['line_total'], 2) }}</td>
                    <td>
                        <span class="badge {{ $item['verified'] ? 'verified' : 'unverified' }}">
                            {{ $item['verified'] ? 'потвърдено' : 'непотвърдено' }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals">
        Общо: {{ number_format($bom['totals']['subtotal'], 2) }} {{ $bom['totals']['currency'] }}
        ({{ $bom['totals']['item_count'] }} позиции, {{ $bom['totals']['component_count'] }} компонента)
    </div>

    @if(!empty($bom['cables']))
        <h2>Таблица на изходящите кръгове</h2>
        <table>
            <thead>
                <tr>
                    <th class="num">№</th>
                    <th>Наименование</th>
                    <th>Апарат</th>
                    <th class="num">In</th>
                    <th>Жила</th>
                    <th>Кабел</th>
                    <th class="num">Дълж.</th>
                    <th class="num">Товар</th>
                    <th class="num">&Delta;U</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bom['cables'] as $cable)
                    <tr>
                        <td class="num">{{ $cable['number'] }}</td>
                        <td>{{ $cable['name'] }}</td>
                        <td>{{ $cable['device_label'] }} · {{ $cable['device_catalog'] }}</td>
                        <td class="num">{{ $cable['rated_current_a'] ? $cable['rated_current_a'].' A' : '—' }}</td>
                        <td>{{ $cable['conductors'] }}</td>
                        <td>{{ $cable['cable_type'] }}</td>
                        <td class="num">{{ $cable['length_m'] ? $cable['length_m'].' m' : '—' }}</td>
                        <td class="num">{{ $cable['load_kw'] !== null ? $cable['load_kw'].' kW' : '—' }}</td>
                        <td class="num {{ ($cable['voltage_drop_percent'] ?? 0) > 4 ? 'bad' : '' }}">
                            {{ $cable['voltage_drop_percent'] !== null ? $cable['voltage_drop_percent'].' %' : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($busbars))
        <h2>Гребенни шини и разпределителни клеми</h2>
        <table>
            <thead>
                <tr>
                    <th>Кат. номер</th>
                    <th>Наименование</th>
                    <th class="num">Ред</th>
                    <th class="num">Обхват</th>
                    <th class="num">Фази</th>
                    <th>Тип връзка</th>
                </tr>
            </thead>
            <tbody>
                @foreach($busbars as $busbar)
                    <tr>
                        <td>{{ $busbar['catalogNumber'] ?? '' }}</td>
                        <td>{{ $busbar['name'] ?? '' }}</td>
                        <td class="num">{{ ($busbar['row'] ?? 0) + 1 }}</td>
                        <td class="num">{{ $busbar['spanModules'] ?? '' }} мод.</td>
                        <td class="num">{{ $busbar['phases'] ?? '' }}</td>
                        <td>{{ $busbar['connectionType'] ?? '' }}</td>
                    </tr>
                @endforeach
                @foreach($bars as $bar)
                    <tr>
                        <td>{{ $bar['catalogNumber'] ?? '' }}</td>
                        <td>{{ $bar['name'] ?? '' }}</td>
                        <td class="num" colspan="2">{{ $bar['usedWays'] ?? 0 }}/{{ $bar['ways'] ?? 0 }} клеми</td>
                        <td class="num">{{ $bar['conductor'] ?? '' }}</td>
                        <td>винтова</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($bom['wires']))
        <h2>Клемен списък / списък на проводниците</h2>
        <table>
            <thead>
                <tr>
                    <th>№ жило</th>
                    <th>Проводник</th>
                    <th class="num">Сечение</th>
                    <th>От</th>
                    <th>До</th>
                    <th class="num">Дълж.</th>
                    <th>Забележка</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bom['wires'] as $wire)
                    <tr>
                        <td>{{ $wire['wire_number'] }}</td>
                        <td>{{ $wire['conductor'] }}</td>
                        <td class="num">{{ $wire['cross_section_mm2'] }} mm²</td>
                        <td>{{ $wire['from'] }}</td>
                        <td>{{ $wire['to'] }}</td>
                        <td class="num">{{ $wire['length_mm'] }} mm</td>
                        <td>{{ $wire['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($bom['legend']))
        <h2>Легенда на кръговете</h2>
        <table>
            <thead><tr><th>Означение</th><th>Описание</th></tr></thead>
            <tbody>
                @foreach($bom['legend'] as $legend)
                    <tr><td>{{ $legend['label'] ?? '' }}</td><td>{{ $legend['description'] ?? '' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($technical))
        <h2>Техническа справка</h2>
        <table>
            <tbody>
                @foreach($technical as $label => $value)
                    <tr><th style="width:40%">{{ $label }}</th><td>{{ $value }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p style="margin-top:20px; font-size:9px; color:#888;">
        Забележка: документът е проектна чернова. Не удостоверява безопасност или съответствие със стандарти
        (Icc, селективност, пад на напрежение, загряване). Изисква проверка от правоспособен проектант.
    </p>
</body>
</html>
