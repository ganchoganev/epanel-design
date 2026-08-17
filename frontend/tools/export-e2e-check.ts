/**
 * Configures a board from loads, wires it, saves it as a project and then pulls
 * every export the backend offers. This is the end-to-end check that the
 * documentation pack really carries the wiring data. Requires the API to be
 * running.
 *
 *   npm run check:exports -- [apiUrl]
 */
import { Injector } from '@angular/core';
import { ConfiguratorService, LoadSpec } from '../src/app/services/configurator.service';
import { WiringService } from '../src/app/services/wiring.service';
import { SchematicService } from '../src/app/services/schematic.service';
import { EtiProduct } from '../src/app/models/catalog.models';
import { DesignData, PlacedComponent, emptyWiring } from '../src/app/models/project.models';

const apiUrl = process.argv[2] ?? 'http://localhost:8010/api/v1';
// Optional second argument: keep the generated PDF here for a visual review.
const outputDir = process.argv[3] ?? null;

let failures = 0;
let checks = 0;
function assert(condition: boolean, message: string): void {
  checks += 1;
  console.log(`  ${condition ? 'ok' : 'FAIL'}: ${message}`);
  if (!condition) failures += 1;
}

/**
 * Reads the sheet titles out of an .xlsx without pulling in a spreadsheet
 * library: the workbook part is a deflated entry in the zip, so inflate it and
 * pick the name attributes out of the XML.
 */
async function workbookSheetNames(xlsx: Buffer): Promise<string[]> {
  const { execFile } = await import('node:child_process');
  const { writeFile, mkdtemp, rm } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');

  const dir = await mkdtemp(join(tmpdir(), 'eti-xlsx-'));
  const file = join(dir, 'bom.xlsx');
  await writeFile(file, xlsx);

  try {
    const xml = await new Promise<string>((resolve, reject) => {
      execFile('unzip', ['-p', file, 'xl/workbook.xml'], { encoding: 'utf8' }, (err, stdout) =>
        err ? reject(err) : resolve(stdout)
      );
    });
    return [...xml.matchAll(/<sheet[^>]*name="([^"]+)"/g)].map((m) => m[1]);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

/** Extracts the text layer of a PDF so the report sections can be asserted on. */
async function pdfToText(pdf: Buffer): Promise<string> {
  const { execFile } = await import('node:child_process');
  const { writeFile, mkdtemp, rm } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');

  const dir = await mkdtemp(join(tmpdir(), 'eti-pdf-'));
  const file = join(dir, 'pack.pdf');
  await writeFile(file, pdf);

  try {
    return await new Promise<string>((resolve, reject) => {
      execFile('pdftotext', ['-layout', file, '-'], { encoding: 'utf8', maxBuffer: 1 << 24 }, (err, stdout) =>
        err ? reject(err) : resolve(stdout)
      );
    });
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

const loads: LoadSpec[] = [
  { id: '1', name: 'Осветление', kind: 'lighting', powerKw: 2.4, quantity: 3, threePhase: false, lengthM: 25 },
  { id: '2', name: 'Контакти', kind: 'sockets', powerKw: 6.0, quantity: 4, threePhase: false, lengthM: 28 },
  { id: '3', name: 'Фурна', kind: 'oven', powerKw: 7.0, quantity: 1, threePhase: true, lengthM: 14 },
  { id: '4', name: 'Бойлер', kind: 'boiler', powerKw: 3.0, quantity: 1, threePhase: false, lengthM: 12 },
  { id: '5', name: 'Зарядна станция', kind: 'ev_charger', powerKw: 11.0, quantity: 1, threePhase: true, lengthM: 20 },
];

async function main(): Promise<void> {
  const catalogResponse = await fetch(`${apiUrl}/products?per_page=500`);
  const catalog = ((await catalogResponse.json()) as { data: EtiProduct[] }).data;

  const wiring = new WiringService();
  const injector = Injector.create({
    providers: [
      { provide: WiringService, useValue: wiring },
      { provide: ConfiguratorService, deps: [] },
    ],
  });
  const configurator = injector.get(ConfiguratorService);

  const result = configurator.configure(
    loads,
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

  console.log('\n== Configuration ==');
  assert(result.unresolved.length === 0, 'configuration resolved completely');

  const byNumber = new Map(catalog.map((p) => [p.catalog_number, p]));
  const modulesPerRow = result.enclosure!.modulesPerRow;
  const components: PlacedComponent[] = [];
  let row = 0;
  let module = 0;

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
      phases: 3,
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
  design.schematicSvg = new SchematicService().buildSvg(design);

  console.log('\n== Save project ==');
  const created = await fetch(`${apiUrl}/projects`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      name: 'Експорт проверка',
      client_name: 'Вътрешен тест',
      design_data: design,
      panel_config: design.enclosure,
    }),
  });
  assert(created.status === 201, `project stored (HTTP ${created.status})`);
  const project = (await created.json()) as { id: number };
  console.log(`  project id ${project.id}`);

  try {
    console.log('\n== BOM ==');
    const bomResponse = await fetch(`${apiUrl}/projects/${project.id}/bom`);
    const bom = (await bomResponse.json()) as {
      items: Array<{ catalog_number: string; quantity: number; line_total: number; currency: string }>;
      cables: unknown[];
      wires: unknown[];
      totals: { currency: string; subtotal: number; item_count: number };
    };
    assert(bomResponse.status === 200, `BOM returns HTTP ${bomResponse.status}`);
    assert(bom.totals.currency === 'EUR', `BOM is priced in EUR (${bom.totals.currency})`);
    assert(
      bom.items.every((i) => i.currency === 'EUR'),
      'every BOM line is priced in EUR'
    );
    assert(bom.items.length > 0, `BOM lists ${bom.items.length} line items`);
    assert(bom.cables.length > 0, `BOM carries a cable schedule (${bom.cables.length} circuits)`);
    assert(bom.wires.length > 0, `BOM carries a wire list (${bom.wires.length} wires)`);
    assert(
      bom.items.some((i) => i.catalog_number === result.enclosure!.catalogNumber),
      'the enclosure is part of the BOM'
    );
    assert(
      design.wiring.busbars.every((b) => bom.items.some((i) => i.catalog_number === b.catalogNumber)),
      'every comb busbar is quantified in the BOM'
    );

    console.log('\n== CSV ==');
    const csvResponse = await fetch(`${apiUrl}/projects/${project.id}/export/csv`);
    const csv = await csvResponse.text();
    assert(csvResponse.status === 200, `CSV returns HTTP ${csvResponse.status}`);
    assert(csv.includes('ИЗХОДЯЩИ КРЪГОВЕ'), 'CSV contains the cable schedule section');
    assert(csv.includes('КЛЕМЕН СПИСЪК'), 'CSV contains the wire list section');
    assert(csv.includes('EUR'), 'CSV is priced in EUR');
    assert(!csv.includes('BGN'), 'CSV has no leftover BGN prices');

    console.log('\n== PDF ==');
    const pdfResponse = await fetch(`${apiUrl}/projects/${project.id}/export/pdf`);
    const pdf = Buffer.from(await pdfResponse.arrayBuffer());
    assert(pdfResponse.status === 200, `PDF returns HTTP ${pdfResponse.status}`);
    assert(pdf.subarray(0, 5).toString() === '%PDF-', 'PDF has a valid header');
    assert(pdf.length > 20000, `PDF is a full pack (${Math.round(pdf.length / 1024)} kB)`);

    const pdfText = await pdfToText(pdf);
    for (const section of [
      'Количествена сметка',
      'Еднолинейна схема',
      'Таблица на изходящите кръгове',
      'Гребенни шини',
      'Клемен списък',
      'Техническа справка',
    ]) {
      assert(pdfText.includes(section), `PDF contains the "${section}" section`);
    }
    assert(pdfText.includes('EUR'), 'PDF is priced in EUR');
    assert(!pdfText.includes('BGN'), 'PDF has no leftover BGN prices');
    if (outputDir) {
      const { writeFile } = await import('node:fs/promises');
      const { join } = await import('node:path');
      await writeFile(join(outputDir, 'project-pack.pdf'), pdf);
      console.log(`  saved to ${join(outputDir, 'project-pack.pdf')}`);
    }

    console.log('\n== Excel ==');
    const xlsxResponse = await fetch(`${apiUrl}/projects/${project.id}/export/excel`);
    const xlsx = Buffer.from(await xlsxResponse.arrayBuffer());
    assert(xlsxResponse.status === 200, `Excel returns HTTP ${xlsxResponse.status}`);
    assert(xlsx.subarray(0, 2).toString() === 'PK', 'Excel file is a valid archive');
    assert(xlsx.length > 5000, `Excel workbook has content (${Math.round(xlsx.length / 1024)} kB)`);

    const sheetNames = await workbookSheetNames(xlsx);
    console.log(`  sheets: ${sheetNames.join(' | ')}`);
    for (const expected of ['Количествена сметка', 'Изходящи кръгове', 'Клемен списък', 'Легенда']) {
      assert(sheetNames.includes(expected), `Excel has the "${expected}" sheet`);
    }
  } finally {
    await fetch(`${apiUrl}/projects/${project.id}`, { method: 'DELETE' });
    console.log(`\n  cleaned up project ${project.id}`);
  }

  console.log(`\n${checks - failures}/${checks} checks passed`);
  if (failures > 0) process.exit(1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
