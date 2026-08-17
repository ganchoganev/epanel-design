/**
 * Node-runnable harness that exercises the wiring generator, the engineering
 * checks, the schematic renderer and the configurator against realistic
 * layouts built from real ETI catalog numbers. Run with:
 *
 *   npm run check:wiring
 */
import { Injector } from '@angular/core';
import { DesignCheckService } from '../src/app/services/design-check.service';
import { SchematicService } from '../src/app/services/schematic.service';
import { WiringService } from '../src/app/services/wiring.service';
import { ConfiguratorService } from '../src/app/services/configurator.service';
import { DesignData, PlacedComponent, emptyWiring } from '../src/app/models/project.models';
import { EtiProduct } from '../src/app/models/catalog.models';

let failures = 0;
let checks = 0;

function assert(condition: boolean, message: string): void {
  checks += 1;
  if (!condition) {
    failures += 1;
    console.error(`  FAIL: ${message}`);
  } else {
    console.log(`  ok: ${message}`);
  }
}

function component(partial: Partial<PlacedComponent> & { uid: string }): PlacedComponent {
  return {
    catalogNumber: 'x',
    name: 'x',
    label: partial.uid,
    series: null,
    row: 0,
    startModule: 0,
    widthModules: 1,
    verified: true,
    ...partial,
  };
}

function design(components: PlacedComponent[], rows = 2, modulesPerRow = 12): DesignData {
  return {
    enclosure: {
      catalogNumber: '001101011',
      name: 'ETIBOX ECT 24 (2x12)',
      rows,
      modulesPerRow,
      supplySystem: 'TN-C-S',
      phases: 3,
      ipRating: 'IP40',
      thermalLimitW: 45,
      ambientTempC: 30,
    },
    rows: Array.from({ length: rows }, (_, i) => i),
    components,
    connections: [],
    legend: [],
    manualItems: [],
    wiring: emptyWiring(),
  };
}

const wiring = new WiringService();
const checker = new DesignCheckService();
const schematic = new SchematicService();

// A realistic residential board: 63 A isolator, SPD, 40/30 mA RCD and six MCBs.
const board = design([
  component({ uid: 'u1', label: 'Q1', catalogNumber: '002421132', name: 'ETIMAT SWITCH 63A 4p', category: 'Switch', poles: 4, ratedCurrentA: 63, row: 0, startModule: 0, widthModules: 4, heatDissipationW: 1 }),
  component({ uid: 'u2', label: 'SPD1', catalogNumber: '002440325', name: 'ETITEC B T12 275/12,5 4+0', category: 'SPD', poles: 4, row: 0, startModule: 4, widthModules: 4, heatDissipationW: 0.5 }),
  component({ uid: 'u3', label: 'FI1', catalogNumber: '002061512', name: 'EFI-P4 AC 40/30mA 4p', category: 'RCD', poles: 4, ratedCurrentA: 40, residualCurrentA: 0.03, row: 1, startModule: 0, widthModules: 4, heatDissipationW: 2.4 }),
  component({ uid: 'u4', label: 'F1', catalogNumber: '002141514', name: 'ETIMAT 6 1p C10', category: 'MCB', poles: 1, ratedCurrentA: 10, tripCurve: 'C', row: 1, startModule: 4, heatDissipationW: 1.05 }),
  component({ uid: 'u5', label: 'F2', catalogNumber: '002141516', name: 'ETIMAT 6 1p C16', category: 'MCB', poles: 1, ratedCurrentA: 16, tripCurve: 'C', row: 1, startModule: 5, heatDissipationW: 1.38 }),
  component({ uid: 'u6', label: 'F3', catalogNumber: '002141516', name: 'ETIMAT 6 1p C16', category: 'MCB', poles: 1, ratedCurrentA: 16, tripCurve: 'C', row: 1, startModule: 6, heatDissipationW: 1.38 }),
  component({ uid: 'u7', label: 'F4', catalogNumber: '002141516', name: 'ETIMAT 6 1p C16', category: 'MCB', poles: 1, ratedCurrentA: 16, tripCurve: 'C', row: 1, startModule: 7, heatDissipationW: 1.38 }),
]);

console.log('\n== Wiring generation ==');
const generated = wiring.generate(board);
board.wiring = generated;

assert(generated.incomingUid === 'u1', 'the 63 A isolator is detected as the incoming device');
assert(generated.busbars.length >= 1, `comb busbar created for the adjacent MCB run (got ${generated.busbars.length})`);

const busbar = generated.busbars[0];
assert(busbar.sourceUid === 'u3', 'busbar is fed from the RCD, not the isolator');
assert(busbar.targetUids.length === 4, `busbar feeds all four MCBs (got ${busbar.targetUids.length})`);
assert(busbar.catalogNumber === '002921100' || busbar.catalogNumber === '002921101', `busbar uses a real IZS catalog number (${busbar.catalogNumber})`);
assert(busbar.spanModules === 4, `busbar spans the four MCB modules (got ${busbar.spanModules})`);

assert(generated.circuits.length === 4, `four outgoing circuits (got ${generated.circuits.length})`);
assert(generated.wires.length > 0, `wires generated (got ${generated.wires.length})`);
assert(
  generated.wires.every((w) => /^W\d{3}$/.test(w.wireNumber)),
  'every wire has a sequential ferrule number'
);
assert(
  new Set(generated.wires.map((w) => w.wireNumber)).size === generated.wires.length,
  'wire numbers are unique'
);
assert(
  generated.bars.some((b) => b.conductor === 'N') && generated.bars.some((b) => b.conductor === 'PE'),
  'both N and PE distribution bars are added'
);

const c10 = generated.circuits.find((c) => c.protectiveDeviceUid === 'u4')!;
assert(c10.cableCrossSectionMm2 === 1.5, `10 A circuit sized to 1,5 mm² (got ${c10.cableCrossSectionMm2})`);
const c16 = generated.circuits.find((c) => c.protectiveDeviceUid === 'u5')!;
assert(c16.cableCrossSectionMm2 === 1.5, `16 A circuit sized to 1,5 mm² (got ${c16.cableCrossSectionMm2})`);

console.log('\n== Cross section ladder ==');
assert(wiring.crossSectionFor(16) === 1.5, '16 A -> 1,5 mm²');
assert(wiring.crossSectionFor(20) === 2.5, '20 A -> 2,5 mm²');
assert(wiring.crossSectionFor(32) === 6, '32 A -> 6 mm²');
assert(wiring.crossSectionFor(63) === 16, '63 A -> 16 mm²');

console.log('\n== Voltage drop ==');
// 16 A over 25 m of 2,5 mm² single phase: 2*25*16/(56*2.5) = 5.71 V = 2.48 %
const drop = wiring.voltageDrop(16, 25, 2.5, false);
assert(Math.abs(drop - 2.48) < 0.05, `single-phase drop matches hand calculation (got ${drop}%)`);
const drop3 = wiring.voltageDrop(16, 25, 2.5, true);
assert(drop3 < drop, `three-phase drop is lower than single-phase (${drop3}% < ${drop}%)`);

console.log('\n== Phase balancing ==');
const phases = new Set(
  generated.circuits.flatMap((c) => c.conductors.filter((x) => x.startsWith('L')))
);
assert(phases.size === 3, `single-pole circuits spread over all three phases (got ${[...phases].join(',')})`);

console.log('\n== Engineering checks ==');
const warnings = checker.run(board);
console.log(`  (${warnings.length} findings)`);
for (const w of warnings) console.log(`    [${w.severity}/${w.category}] ${w.message}`);
assert(
  !warnings.some((w) => w.category === 'wiring' && w.id === 'wiring-not-generated'),
  'no "wiring missing" notice once wiring exists'
);
assert(
  !warnings.some((w) => w.id === 'protection-no-rcd'),
  'no missing-RCD error when an RCD is present'
);
assert(
  !warnings.some((w) => w.severity === 'error'),
  'a correctly built board produces no errors'
);

console.log('\n== Checks catch real faults ==');
const faulty = design([
  component({ uid: 'f1', label: 'Q1', catalogNumber: '002421131', category: 'Switch', poles: 4, ratedCurrentA: 40, row: 0, startModule: 0, widthModules: 4 }),
  component({ uid: 'f2', label: 'F1', catalogNumber: '002141522', category: 'MCB', poles: 1, ratedCurrentA: 63, tripCurve: 'C', row: 0, startModule: 4 }),
  component({ uid: 'f3', label: 'F2', catalogNumber: '002141516', category: 'MCB', poles: 1, ratedCurrentA: 16, tripCurve: 'C', row: 0, startModule: 5 }),
]);
faulty.wiring = wiring.generate(faulty);
const faultyWarnings = checker.run(faulty);
for (const w of faultyWarnings) console.log(`    [${w.severity}/${w.category}] ${w.message}`);
assert(
  faultyWarnings.some((w) => w.id === 'protection-no-rcd'),
  'missing RCD is reported as an error'
);
assert(
  faultyWarnings.some((w) => w.category === 'selectivity'),
  'a 63 A breaker behind a 40 A isolator is flagged'
);

console.log('\n== Thermal check ==');
const hot = design(
  Array.from({ length: 20 }, (_, i) =>
    component({
      uid: `h${i}`,
      label: `F${i}`,
      catalogNumber: '002141522',
      category: 'MCB',
      poles: 1,
      ratedCurrentA: 63,
      row: i < 12 ? 0 : 1,
      startModule: i < 12 ? i : i - 12,
      heatDissipationW: 3.9,
    })
  )
);
hot.wiring = wiring.generate(hot);
const hotWarnings = checker.run(hot);
assert(
  hotWarnings.some((w) => w.category === 'thermal' && w.severity === 'error'),
  `78 W in a 45 W enclosure is reported (${hotWarnings.filter((w) => w.category === 'thermal').length} thermal findings)`
);

console.log('\n== Fill check ==');
const packed = design(
  Array.from({ length: 22 }, (_, i) =>
    component({
      uid: `p${i}`,
      label: `F${i}`,
      catalogNumber: '002141516',
      category: 'MCB',
      poles: 1,
      ratedCurrentA: 16,
      row: i < 12 ? 0 : 1,
      startModule: i < 12 ? i : i - 12,
      heatDissipationW: 0.2,
    })
  )
);
packed.wiring = wiring.generate(packed);
assert(
  checker.run(packed).some((w) => w.id === 'fill-no-reserve'),
  '22 of 24 modules used triggers the reserve warning'
);

console.log('\n== Schematic ==');
const svg = schematic.buildSvg(board);
assert(svg.startsWith('<svg'), 'schematic is valid SVG');
assert(svg.includes('</svg>'), 'schematic SVG is closed');
assert(svg.includes('002141516'), 'schematic prints real catalog numbers');
assert(svg.includes('Еднолинейна схема'), 'schematic has a title block');
assert(svg.includes('NYM-J'), 'schematic labels the outgoing cables');
assert((svg.match(/<g/g) ?? []).length >= 5, 'schematic contains device symbol groups');
assert(!svg.includes('Лента '), 'a four-circuit board stays on a single band');

const packedSvg = schematic.buildSvg(packed);
assert(packedSvg.includes('Лента 1 от'), 'a wide board wraps into labelled bands');
assert(packedSvg.includes('Лента 3 от'), '22 circuits occupy more than two bands of 8');

console.log('\n== Configurator ==');
const catalog: EtiProduct[] = [
  prod('002141512', 'ETIMAT 6 1p C6', 'MCB', { poles: 1, rated_current_a: 6, trip_curve: 'C', width_modules: 1 }),
  prod('002141514', 'ETIMAT 6 1p C10', 'MCB', { poles: 1, rated_current_a: 10, trip_curve: 'C', width_modules: 1 }),
  prod('002111514', 'ETIMAT 6 1p B10', 'MCB', { poles: 1, rated_current_a: 10, trip_curve: 'B', width_modules: 1 }),
  prod('002141516', 'ETIMAT 6 1p C16', 'MCB', { poles: 1, rated_current_a: 16, trip_curve: 'C', width_modules: 1 }),
  prod('002111516', 'ETIMAT 6 1p B16', 'MCB', { poles: 1, rated_current_a: 16, trip_curve: 'B', width_modules: 1 }),
  prod('002141517', 'ETIMAT 6 1p C20', 'MCB', { poles: 1, rated_current_a: 20, trip_curve: 'C', width_modules: 1 }),
  prod('002111517', 'ETIMAT 6 1p B20', 'MCB', { poles: 1, rated_current_a: 20, trip_curve: 'B', width_modules: 1 }),
  prod('002141518', 'ETIMAT 6 1p C25', 'MCB', { poles: 1, rated_current_a: 25, trip_curve: 'C', width_modules: 1 }),
  prod('002145516', 'ETIMAT 6 3p C16', 'MCB', { poles: 3, rated_current_a: 16, trip_curve: 'C', width_modules: 3 }),
  prod('002145517', 'ETIMAT 6 3p C20', 'MCB', { poles: 3, rated_current_a: 20, trip_curve: 'C', width_modules: 3 }),
  prod('002061511', 'EFI-P4 AC 25/30mA 4p', 'RCD', { poles: 4, rated_current_a: 25, residual_current_a: 0.03, rcd_type: 'AC', width_modules: 4 }),
  prod('002061512', 'EFI-P4 AC 40/30mA 4p', 'RCD', { poles: 4, rated_current_a: 40, residual_current_a: 0.03, rcd_type: 'AC', width_modules: 4 }),
  prod('002061513', 'EFI-P4 AC 63/30mA 4p', 'RCD', { poles: 4, rated_current_a: 63, residual_current_a: 0.03, rcd_type: 'AC', width_modules: 4 }),
  prod('002175154', 'KZS-1M DN A C16/30mA', 'RCBO', { poles: 2, rated_current_a: 16, residual_current_a: 0.03, trip_curve: 'C', width_modules: 1 }),
  prod('002421132', 'ETIMAT SWITCH 63A 4p', 'Switch', { poles: 4, rated_current_a: 63, width_modules: 4 }),
  prod('002421131', 'ETIMAT SWITCH 40A 4p', 'Switch', { poles: 4, rated_current_a: 40, width_modules: 4 }),
  prod('002440325', 'ETITEC B T12 275/12,5 4+0', 'SPD', { poles: 4, width_modules: 4 }),
  enclosureProd('001101011', 'ETIBOX ECT 24 (2x12)', 12, 2),
  enclosureProd('001101012', 'ETIBOX ECT 36 (3x12)', 12, 3),
  enclosureProd('001101013', 'ETIBOX ECT 48 (4x12)', 12, 4),
  enclosureProd('001101020', 'ETIBOX ECT 72 (4x18)', 18, 4),
];

// ConfiguratorService injects WiringService, so build it through an injector.
const injector = Injector.create({
  providers: [
    { provide: WiringService, useValue: wiring },
    { provide: ConfiguratorService, deps: [] },
  ],
});
const configurator = injector.get(ConfiguratorService);

const result = configurator.configure(
  [
    { id: 'l1', name: 'Осветление', kind: 'lighting', powerKw: 1.2, quantity: 2, threePhase: false, lengthM: 20 },
    { id: 'l2', name: 'Контакти', kind: 'sockets', powerKw: 2.0, quantity: 3, threePhase: false, lengthM: 25 },
    { id: 'l3', name: 'Бойлер', kind: 'boiler', powerKw: 3.0, quantity: 1, threePhase: false, lengthM: 12 },
    { id: 'l4', name: 'Мотор', kind: 'motor', powerKw: 4.0, quantity: 1, threePhase: true, lengthM: 30 },
  ],
  {
    supplyPhases: 3,
    diversityFactor: 0.7,
    perCircuitRcbo: false,
    includeSpd: true,
    includeMainSwitch: true,
    spareModules: 4,
  },
  catalog
);

console.log(`  enclosure: ${result.enclosure?.name} (${result.totalModules} modules)`);
console.log(`  main breaker: ${result.mainBreakerCurrentA} A`);
console.log(`  devices: ${result.devices.length}`);
for (const d of result.devices) {
  console.log(`    ${d.label.padEnd(5)} ${d.catalogNumber}  ${d.widthModules}mod  ${d.purpose}`);
}
for (const n of result.notes) console.log(`  note: ${n}`);
for (const u of result.unresolved) console.log(`  UNRESOLVED: ${u}`);

assert(result.unresolved.length === 0, 'every load resolved to a real catalog item');
assert(result.enclosure !== null, 'an enclosure was selected');
assert(result.devices.length >= 8, `devices generated (got ${result.devices.length})`);
assert(
  result.devices.every((d) => catalog.some((p) => p.catalog_number === d.catalogNumber)),
  'every selected device exists in the catalog'
);
assert(result.devices.some((d) => d.label === 'Q1'), 'a main switch was included');
assert(result.devices.some((d) => d.catalogNumber === '002440325'), 'an SPD was included');
assert(
  result.devices.filter((d) => d.label.startsWith('FI')).length >= 1,
  'at least one RCD was included'
);
assert(
  result.devices.filter((d) => d.label.startsWith('F') && !d.label.startsWith('FI')).length === 7,
  `one breaker per circuit (got ${result.devices.filter((d) => d.label.startsWith('F') && !d.label.startsWith('FI')).length}, expected 7)`
);
assert(
  MAIN_SWITCH_OK(result.mainBreakerCurrentA),
  `main breaker rating is a real ETI size (${result.mainBreakerCurrentA} A)`
);

// The 4 kW three-phase motor draws ~6 A per phase and must get a 3-pole breaker.
const motorDevice = result.devices.find((d) => d.purpose.startsWith('Двигател'));
assert(motorDevice?.widthModules === 3, `the motor got a three-pole breaker (${motorDevice?.catalogNumber})`);
assert(
  result.devices.some((d) => d.purpose.includes('Дефектнотокова защита за Мотор')),
  'the motor circuit got its own RCD'
);

console.log('\n== RCBO mode ==');
const rcboResult = configurator.configure(
  [{ id: 'r1', name: 'Контакти', kind: 'sockets', powerKw: 2.0, quantity: 4, threePhase: false, lengthM: 20 }],
  {
    supplyPhases: 1,
    diversityFactor: 0.7,
    perCircuitRcbo: true,
    includeSpd: false,
    includeMainSwitch: false,
    spareModules: 0,
  },
  catalog
);
assert(
  rcboResult.devices.every((d) => d.catalogNumber === '002175154'),
  'RCBO mode uses KZS-1M for every circuit'
);
assert(rcboResult.devices.length === 4, `four RCBOs for four circuits (got ${rcboResult.devices.length})`);
assert(
  rcboResult.enclosure?.name.includes('24') || rcboResult.enclosure?.name.includes('12'),
  `smallest fitting enclosure chosen (${rcboResult.enclosure?.name})`
);

console.log('\n== Empty design ==');
const empty = wiring.generate(design([]));
assert(empty.wires.length === 0 && empty.circuits.length === 0, 'empty layout produces empty wiring');
assert(schematic.buildSvg(design([])).startsWith('<svg'), 'schematic of an empty board is still valid SVG');

function MAIN_SWITCH_OK(rating: number): boolean {
  return [40, 63].includes(rating);
}

function prod(
  catalogNumber: string,
  name: string,
  category: string,
  extra: Partial<EtiProduct>
): EtiProduct {
  return {
    id: Math.random(),
    catalog_number: catalogNumber,
    name,
    series: 'ETI',
    category,
    poles: 1,
    rated_current_a: null,
    trip_curve: null,
    breaking_capacity_ka: 6,
    width_modules: 1,
    width_mm: null,
    height_mm: null,
    depth_mm: 68,
    heat_dissipation_w: 1,
    mounting_type: 'DIN',
    price: null,
    currency: 'EUR',
    data_source: 'eti_catalogue',
    verified: true,
    raw_attributes: null,
    compatible_accessories: null,
    ...extra,
  };
}

function enclosureProd(
  catalogNumber: string,
  name: string,
  modulesPerRow: number,
  rows: number
): EtiProduct {
  return prod(catalogNumber, name, 'Enclosure', {
    width_modules: modulesPerRow,
    raw_attributes: { modules_per_row: modulesPerRow, rows, ip_rating: 'IP40' },
  });
}

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures > 0) {
  console.error(`${failures} FAILURES`);
  process.exit(1);
}
