/**
 * Runs the configurator and the wiring generator against the live catalog
 * served by the backend, so the whole chain is exercised with the real ETI data
 * rather than fixtures. Requires the API to be running.
 *
 *   npm run check:configurator -- [apiUrl]
 */
import { Injector } from '@angular/core';
import { ConfiguratorService, LoadSpec } from '../src/app/services/configurator.service';
import { WiringService } from '../src/app/services/wiring.service';
import { DesignCheckService } from '../src/app/services/design-check.service';
import { SchematicService } from '../src/app/services/schematic.service';
import { EtiProduct } from '../src/app/models/catalog.models';
import { DesignData, PlacedComponent, emptyWiring } from '../src/app/models/project.models';

const apiUrl = process.argv[2] ?? 'http://localhost:8010/api/v1';

let failures = 0;
let checks = 0;
function assert(condition: boolean, message: string): void {
  checks += 1;
  console.log(`  ${condition ? 'ok' : 'FAIL'}: ${message}`);
  if (!condition) failures += 1;
}

async function main(): Promise<void> {
  const response = await fetch(`${apiUrl}/products?per_page=500`);
  const payload = (await response.json()) as { data: EtiProduct[] };
  const catalog = payload.data;

  console.log(`\n== Live catalog ==`);
  console.log(`  ${catalog.length} products loaded from ${apiUrl}`);
  const categories = [...new Set(catalog.map((p) => p.category))].sort();
  console.log(`  categories: ${categories.join(', ')}`);

  assert(catalog.length > 100, `catalog is populated (${catalog.length} products)`);
  assert(
    catalog.every((p) => p.currency === 'EUR'),
    'every product is priced in EUR'
  );
  assert(
    catalog.some((p) => p.catalog_number === '002141516'),
    'the real ETIMAT 6 1p C16 order code 002141516 is present'
  );
  assert(
    catalog.some((p) => p.catalog_number === '002921100'),
    'the real IZS10/1F/12 busbar 002921100 is present'
  );
  assert(
    catalog.every((p) => /^\d{9}$/.test(p.catalog_number)),
    'all catalog numbers are 9-digit ETI order codes'
  );

  const wiring = new WiringService();
  const checker = new DesignCheckService();
  const schematic = new SchematicService();
  const injector = Injector.create({
    providers: [
      { provide: WiringService, useValue: wiring },
      { provide: ConfiguratorService, deps: [] },
    ],
  });
  const configurator = injector.get(ConfiguratorService);

  const scenarios: Array<{ title: string; loads: LoadSpec[]; phases: 1 | 3 }> = [
    {
      // A single-phase 230 V supply is limited to about 14 kW at 63 A, so the
      // loads here stay inside what such a service can actually carry.
      title: 'Апартамент, монофазно',
      phases: 1,
      loads: [
        { id: '1', name: 'Осветление', kind: 'lighting', powerKw: 1.2, quantity: 2, threePhase: false, lengthM: 18 },
        { id: '2', name: 'Контакти', kind: 'sockets', powerKw: 3.0, quantity: 2, threePhase: false, lengthM: 22 },
        { id: '3', name: 'Кухня', kind: 'kitchen', powerKw: 3.5, quantity: 1, threePhase: false, lengthM: 12 },
        { id: '4', name: 'Бойлер', kind: 'boiler', powerKw: 2.0, quantity: 1, threePhase: false, lengthM: 10 },
      ],
    },
    {
      title: 'Къща, трифазно, с фурна и зарядна станция',
      phases: 3,
      loads: [
        { id: '1', name: 'Осветление', kind: 'lighting', powerKw: 2.4, quantity: 4, threePhase: false, lengthM: 25 },
        { id: '2', name: 'Контакти', kind: 'sockets', powerKw: 6.0, quantity: 5, threePhase: false, lengthM: 28 },
        { id: '3', name: 'Фурна и плот', kind: 'oven', powerKw: 7.0, quantity: 1, threePhase: true, lengthM: 14 },
        { id: '4', name: 'Бойлер', kind: 'boiler', powerKw: 3.0, quantity: 1, threePhase: false, lengthM: 12 },
        { id: '5', name: 'Зарядна станция', kind: 'ev_charger', powerKw: 11.0, quantity: 1, threePhase: true, lengthM: 20 },
      ],
    },
    {
      title: 'Работилница с двигатели',
      phases: 3,
      loads: [
        { id: '1', name: 'Осветление цех', kind: 'lighting', powerKw: 3.0, quantity: 2, threePhase: false, lengthM: 35 },
        { id: '2', name: 'Контакти цех', kind: 'sockets', powerKw: 4.0, quantity: 2, threePhase: false, lengthM: 30 },
        { id: '3', name: 'Струг', kind: 'motor', powerKw: 5.5, quantity: 1, threePhase: true, lengthM: 25 },
        { id: '4', name: 'Компресор', kind: 'motor', powerKw: 4.0, quantity: 1, threePhase: true, lengthM: 18 },
      ],
    },
  ];

  for (const scenario of scenarios) {
    console.log(`\n== ${scenario.title} ==`);
    const result = configurator.configure(
      scenario.loads,
      {
        supplyPhases: scenario.phases,
        diversityFactor: 0.7,
        perCircuitRcbo: false,
        includeSpd: true,
        includeMainSwitch: true,
        spareModules: 4,
      },
      catalog
    );

    console.log(`  ${result.enclosure?.name} · главен ${result.mainBreakerCurrentA} A · ${result.totalModules} мод.`);
    for (const d of result.devices) {
      console.log(`    ${d.label.padEnd(5)} ${d.catalogNumber}  ${d.widthModules}mod  ${d.purpose}`);
    }
    for (const n of result.notes) console.log(`    note: ${n}`);
    for (const u of result.unresolved) console.log(`    UNRESOLVED: ${u}`);

    assert(result.unresolved.length === 0, `${scenario.title}: everything resolved from the real catalog`);
    assert(result.enclosure !== null, `${scenario.title}: a real ETIBOX was selected`);
    assert(
      result.devices.every((d) => catalog.some((p) => p.catalog_number === d.catalogNumber)),
      `${scenario.title}: every device is a real catalog item`
    );

    // Push the configuration through the layout and wiring stages.
    const byNumber = new Map(catalog.map((p) => [p.catalog_number, p]));
    const components: PlacedComponent[] = [];
    let row = 0;
    let module = 0;
    const modulesPerRow = result.enclosure!.modulesPerRow;

    for (const device of result.devices) {
      const product = byNumber.get(device.catalogNumber)!;
      const width = Math.max(1, product.width_modules || 1);
      if (module + width > modulesPerRow) {
        row += 1;
        module = 0;
      }
      components.push({
        uid: `${device.label}-${row}-${module}`,
        catalogNumber: device.catalogNumber,
        name: product.name,
        label: device.label,
        series: product.series,
        category: product.category,
        row,
        startModule: module,
        widthModules: width,
        verified: product.verified,
        poles: product.poles ?? 1,
        ratedCurrentA: product.rated_current_a ? Number(product.rated_current_a) : null,
        residualCurrentA: product.residual_current_a ? Number(product.residual_current_a) : null,
        tripCurve: product.trip_curve,
        heatDissipationW: product.heat_dissipation_w ? Number(product.heat_dissipation_w) : null,
      });
      module += width;
    }

    const design: DesignData = {
      enclosure: {
        catalogNumber: result.enclosure!.catalogNumber,
        name: result.enclosure!.name,
        rows: result.enclosure!.rows,
        modulesPerRow,
        supplySystem: 'TN-C-S',
        phases: scenario.phases,
        ipRating: 'IP40',
        thermalLimitW: 20 + result.enclosure!.rows * modulesPerRow * 1.4,
        ambientTempC: 30,
      },
      rows: Array.from({ length: result.enclosure!.rows }, (_, i) => i),
      components,
      connections: [],
      legend: result.devices.map((d) => ({ label: d.label, description: d.purpose })),
      manualItems: [],
      wiring: emptyWiring(),
    };
    design.wiring = wiring.generate(design);

    console.log(
      `    wiring: ${design.wiring.wires.length} проводника, ${design.wiring.busbars.length} шини, ${design.wiring.circuits.length} кръга`
    );
    assert(design.wiring.circuits.length > 0, `${scenario.title}: circuits derived from the layout`);
    assert(
      design.wiring.busbars.every((b) => byNumber.has(b.catalogNumber)),
      `${scenario.title}: busbars reference real catalog numbers`
    );
    assert(
      design.wiring.bars.every((b) => byNumber.has(b.catalogNumber)),
      `${scenario.title}: N/PE bars reference real catalog numbers`
    );

    const findings = checker.run(design);
    for (const f of findings) console.log(`    [${f.severity}/${f.category}] ${f.message}`);
    assert(
      !findings.some((f) => f.severity === 'error'),
      `${scenario.title}: configured panel has no errors (${findings.length} findings)`
    );

    const svg = schematic.buildSvg(design);
    assert(svg.startsWith('<svg') && svg.includes('</svg>'), `${scenario.title}: schematic renders`);
  }

  // An installation drawing more than the largest modular isolator must be
  // reported rather than silently fitted with an undersized main switch.
  console.log('\n== Over-capacity installation ==');
  const overloaded = configurator.configure(
    [
      { id: '1', name: 'Контакти', kind: 'sockets', powerKw: 12.0, quantity: 4, threePhase: false, lengthM: 20 },
      { id: '2', name: 'Бойлери', kind: 'boiler', powerKw: 9.0, quantity: 3, threePhase: false, lengthM: 12 },
    ],
    {
      supplyPhases: 1,
      diversityFactor: 0.9,
      perCircuitRcbo: false,
      includeSpd: false,
      includeMainSwitch: true,
      spareModules: 0,
    },
    catalog
  );
  for (const u of overloaded.unresolved) console.log(`    ${u}`);
  assert(
    overloaded.unresolved.some((u) => u.includes('MCCB')),
    'demand above the largest modular isolator is reported'
  );

  console.log(`\n${checks - failures}/${checks} checks passed`);
  if (failures > 0) process.exit(1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
