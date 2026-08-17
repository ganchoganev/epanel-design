import { Injectable } from '@angular/core';
import {
  CONDUCTOR_COLORS,
  Circuit,
  Conductor,
  DesignData,
  PlacedComponent,
} from '../models/project.models';

const COL_WIDTH = 118;
const LEFT_MARGIN = 90;

/** Height of the title and supply block that opens the sheet. */
const HEADER_HEIGHT = 96;
/** Distance from the top of a band to its main busbar. */
const BAND_BUS_OFFSET = 22;
/** Vertical space a circuit column needs below the bus it hangs from. */
const CIRCUIT_DEPTH = 168;
/** Extra depth a band needs when a shared RCD sits above its circuits. */
const GROUP_DEVICE_DEPTH = 82;
/** Gap between the lowest circuit text and the PE bar closing the band. */
const BAND_FOOTER = 30;
/** Circuits per band. Eight columns stay legible across a portrait A4 page. */
const DEFAULT_COLUMNS_PER_BAND = 8;

interface CircuitGroup {
  key: string;
  devices: PlacedComponent[];
  circuits: Circuit[];
}

/** A group as it appears within one band, possibly a slice of a larger group. */
interface BandGroup {
  group: CircuitGroup;
  circuits: Circuit[];
  continuation: boolean;
}

interface Band {
  groups: BandGroup[];
  columns: number;
  top: number;
  busY: number;
  height: number;
  hasSharedDevice: boolean;
}

export interface SchematicOptions {
  /**
   * Maximum circuits drawn side by side before the diagram wraps onto a new
   * band. Lower values suit narrow paper, higher values suit wide screens.
   */
  maxColumnsPerBand?: number;
}

/**
 * Builds the single-line diagram of a panel as SVG.
 *
 * The drawing follows the usual layout for distribution boards: the supply
 * enters top-left, runs through the incoming device onto a horizontal busbar,
 * and every outgoing circuit drops vertically from that bus through its
 * protective devices to a terminal at the bottom. Devices are drawn with IEC
 * 60617 symbols so the sheet reads like a normal schematic.
 */
@Injectable({ providedIn: 'root' })
export class SchematicService {
  buildSvg(design: DesignData, options: SchematicOptions = {}): string {
    const perBand = Math.max(1, options.maxColumnsPerBand ?? DEFAULT_COLUMNS_PER_BAND);
    const byUid = new Map(design.components.map((c) => [c.uid, c]));
    const incoming = design.wiring.incomingUid ? byUid.get(design.wiring.incomingUid) : undefined;

    // Group circuits by the distribution device feeding them so RCD groups are
    // drawn as one block with a shared bus, like on a real drawing.
    const groups = this.groupCircuits(design, design.wiring.circuits);
    const bands = this.layoutBands(groups, perBand);

    const widestBand = bands.reduce((m, b) => Math.max(m, b.columns), 1);
    const width = LEFT_MARGIN + widestBand * COL_WIDTH + 80;
    const height = bands.length
      ? bands[bands.length - 1].top + bands[bands.length - 1].height + 16
      : HEADER_HEIGHT + CIRCUIT_DEPTH;

    const parts: string[] = [];
    parts.push(this.defs());
    parts.push(this.titleBlock(design, width, height));
    parts.push(this.supplyBlock(design, incoming, bands[0]?.busY));

    bands.forEach((band, index) => {
      parts.push(this.bandBlock(band, index, bands.length, width, design, byUid));
    });

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" font-family="Inter, Arial, sans-serif">
<rect width="${width}" height="${height}" fill="#ffffff"/>
${parts.join('\n')}
</svg>`;
  }

  /**
   * Packs the circuit groups into bands of at most `perBand` columns. A group
   * wider than a band is split across several, and the continuation slices
   * repeat the shared device so each band reads on its own.
   */
  private layoutBands(groups: CircuitGroup[], perBand: number): Band[] {
    const bands: Band[] = [];
    let current: BandGroup[] = [];
    let used = 0;

    const flush = () => {
      if (!current.length) return;
      bands.push({
        groups: current,
        columns: used,
        top: 0,
        busY: 0,
        height: 0,
        hasSharedDevice: current.some((g) => g.group.devices.length > 0),
      });
      current = [];
      used = 0;
    };

    for (const group of groups) {
      let offset = 0;
      let continuation = false;

      while (offset < group.circuits.length) {
        if (used >= perBand) flush();

        const take = Math.min(perBand - used, group.circuits.length - offset);
        current.push({
          group,
          circuits: group.circuits.slice(offset, offset + take),
          continuation,
        });
        used += take;
        offset += take;
        continuation = true;
      }
    }
    flush();

    let top = HEADER_HEIGHT;
    for (const band of bands) {
      band.top = top;
      band.busY = top + BAND_BUS_OFFSET;
      band.height =
        BAND_BUS_OFFSET +
        (band.hasSharedDevice ? GROUP_DEVICE_DEPTH : 0) +
        CIRCUIT_DEPTH +
        BAND_FOOTER;
      top += band.height;
    }

    return bands;
  }

  /** One horizontal band: its own main bus, circuit columns and PE bar. */
  private bandBlock(
    band: Band,
    index: number,
    total: number,
    width: number,
    design: DesignData,
    byUid: Map<string, PlacedComponent>
  ): string {
    const parts: string[] = [];
    parts.push(this.mainBus(width, design, band.busY));

    if (total > 1) {
      parts.push(
        `<text x="24" y="${band.busY - 8}" font-size="8" fill="#999">Лента ${index + 1} от ${total}</text>`
      );
    }

    let column = 0;
    for (const bandGroup of band.groups) {
      parts.push(this.groupBlock(bandGroup, column, band, design, byUid));
      column += bandGroup.circuits.length;
    }

    parts.push(this.protectiveBar(width, band.top + band.height - BAND_FOOTER + 8));

    return `<g>${parts.join('\n')}</g>`;
  }

  private defs(): string {
    return `<defs>
  <marker id="dot" markerWidth="6" markerHeight="6" refX="3" refY="3">
    <circle cx="3" cy="3" r="2.2" fill="#111"/>
  </marker>
</defs>`;
  }

  private titleBlock(design: DesignData, width: number, height: number): string {
    const generated = design.wiring.generatedAt
      ? new Date(design.wiring.generatedAt).toLocaleString('bg-BG')
      : '—';
    return `<g>
  <text x="16" y="24" font-size="14" font-weight="600" fill="#111">Еднолинейна схема — ${this.escape(
    design.enclosure.name
  )}</text>
  <text x="16" y="40" font-size="10" fill="#666">Система ${design.enclosure.supplySystem ?? 'TN-C-S'} · ${
      design.enclosure.phases ?? 3
    }-фазно захранване · генерирана ${generated}</text>
  <rect x="8" y="8" width="${width - 16}" height="${height - 16}" fill="none" stroke="#ccc"/>
</g>`;
  }

  private supplyBlock(design: DesignData, incoming?: PlacedComponent, firstBusY?: number): string {
    const phases = design.enclosure.phases ?? 3;
    const conductors: Conductor[] =
      phases === 3 ? ['L1', 'L2', 'L3', 'N', 'PE'] : ['L1', 'N', 'PE'];

    const rows = conductors
      .map((c, i) => {
        const y = 58 + i * 9;
        return `<line x1="16" y1="${y}" x2="60" y2="${y}" stroke="${CONDUCTOR_COLORS[c]}" stroke-width="2"/>
<text x="64" y="${y + 3}" font-size="8" fill="#555">${c}</text>`;
      })
      .join('\n');

    const label = incoming
      ? `${incoming.label} · ${this.escape(incoming.name)}`
      : 'Главен прекъсвач (не е зададен)';

    const symbol = incoming
      ? this.deviceSymbol(incoming, LEFT_MARGIN - 26, 64, 52)
      : '';
    const feeder =
      firstBusY && firstBusY > 94
        ? `<line x1="${LEFT_MARGIN}" y1="94" x2="${LEFT_MARGIN}" y2="${firstBusY}" stroke="#111" stroke-width="1.4"/>`
        : '';

    return `<g>
  ${rows}
  <text x="16" y="54" font-size="9" font-weight="600" fill="#333">Захранване</text>
  ${symbol}
  ${feeder}
  <text x="${LEFT_MARGIN + 34}" y="72" font-size="9" font-weight="600" fill="#111">${this.escape(
      label
    )}</text>
  ${
    incoming?.ratedCurrentA
      ? `<text x="${LEFT_MARGIN + 34}" y="84" font-size="8" fill="#666">${
          incoming.ratedCurrentA
        } A · ${incoming.poles ?? 1}p · ${this.escape(incoming.catalogNumber)}</text>`
      : ''
  }
</g>`;
  }

  private mainBus(width: number, design: DesignData, busY: number): string {
    const phases = design.enclosure.phases ?? 3;
    const conductors: Conductor[] = phases === 3 ? ['L1', 'L2', 'L3', 'N'] : ['L1', 'N'];
    return conductors
      .map((c, i) => {
        const y = busY + i * 6;
        return `<line x1="24" y1="${y}" x2="${width - 40}" y2="${y}" stroke="${
          CONDUCTOR_COLORS[c]
        }" stroke-width="2.5"/>
<text x="${width - 34}" y="${y + 3}" font-size="8" fill="#555">${c}</text>`;
      })
      .join('\n');
  }

  private protectiveBar(width: number, y: number): string {
    return `<g>
  <line x1="24" y1="${y}" x2="${width - 40}" y2="${y}" stroke="${
      CONDUCTOR_COLORS.PE
    }" stroke-width="2.5"/>
  <text x="${width - 34}" y="${y + 3}" font-size="8" fill="#555">PE</text>
  <text x="24" y="${y - 6}" font-size="8" fill="#666">Защитна шина PE</text>
</g>`;
  }

  /**
   * Circuits sharing an upstream distribution device (typically an RCD) form a
   * block: the RCD is drawn once and its outgoing bus feeds all its circuits.
   */
  private groupCircuits(design: DesignData, circuits: Circuit[]): CircuitGroup[] {
    const byUid = new Map(design.components.map((c) => [c.uid, c]));
    const feeder = new Map<string, string | null>();

    for (const busbar of design.wiring.busbars) {
      for (const uid of busbar.targetUids) feeder.set(uid, busbar.sourceUid);
    }
    for (const wire of design.wiring.wires) {
      if (wire.conductor === 'PE' || wire.viaBusbarId) continue;
      if (!feeder.has(wire.to.componentUid)) {
        feeder.set(wire.to.componentUid, wire.from.componentUid);
      }
    }

    const groups = new Map<string, CircuitGroup>();

    for (const circuit of circuits) {
      const upstreamUid = feeder.get(circuit.protectiveDeviceUid) ?? null;
      const upstream = upstreamUid ? byUid.get(upstreamUid) : undefined;
      const isDistribution =
        upstream && (upstream.category ?? '').toUpperCase().match(/RCD|SWITCH/);
      const key = isDistribution ? upstream!.uid : '__direct__';

      if (!groups.has(key)) {
        groups.set(key, {
          key,
          devices: isDistribution ? [upstream!] : [],
          circuits: [],
        });
      }
      groups.get(key)!.circuits.push(circuit);
    }

    return [...groups.values()];
  }

  private groupBlock(
    bandGroup: BandGroup,
    startColumn: number,
    band: Band,
    design: DesignData,
    byUid: Map<string, PlacedComponent>
  ): string {
    const group = bandGroup.group;
    const span = bandGroup.circuits.length;
    const parts: string[] = [];
    const x0 = LEFT_MARGIN + startColumn * COL_WIDTH;
    const centre = x0 + (span * COL_WIDTH) / 2;
    // All circuit columns in a band hang from the same height so their symbols
    // line up, whether or not their group has a shared device above it.
    const circuitTop = band.busY + (band.hasSharedDevice ? GROUP_DEVICE_DEPTH : 0);

    // The shared distribution device (RCD) sits above the group's own bus.
    if (group.devices.length) {
      const rcd = group.devices[0];
      const deviceY = band.busY + 24;
      parts.push(
        `<line x1="${centre}" y1="${band.busY}" x2="${centre}" y2="${deviceY}" stroke="#111" stroke-width="1.2"/>`
      );
      parts.push(this.deviceSymbol(rcd, centre - 26, deviceY, 52));
      parts.push(
        `<text x="${centre + 32}" y="${deviceY + 16}" font-size="8.5" font-weight="600" fill="#111">${this.escape(
          rcd.label
        )}${bandGroup.continuation ? ' (продълж.)' : ''}</text>`
      );
      parts.push(
        `<text x="${centre + 32}" y="${deviceY + 26}" font-size="7.5" fill="#666">${
          rcd.ratedCurrentA ?? ''
        }A/${((rcd.residualCurrentA ?? 0) * 1000).toFixed(0)}mA ${rcd.poles ?? ''}p</text>`
      );
      parts.push(
        `<text x="${centre + 32}" y="${deviceY + 35}" font-size="7" fill="#999">${this.escape(
          rcd.catalogNumber
        )}</text>`
      );

      // Group bus feeding all circuits below the RCD.
      parts.push(
        `<line x1="${x0 + 20}" y1="${circuitTop}" x2="${
          x0 + span * COL_WIDTH - 20
        }" y2="${circuitTop}" stroke="#111" stroke-width="2"/>`
      );
    }

    bandGroup.circuits.forEach((circuit, i) => {
      const x = x0 + i * COL_WIDTH + COL_WIDTH / 2;
      const device = byUid.get(circuit.protectiveDeviceUid);
      if (!device) return;

      // Groups without a shared device tap the main bus directly, so their drop
      // line reaches down from there to the common symbol row.
      const top = group.devices.length ? circuitTop : band.busY;
      const symbolY = circuitTop + 26;

      parts.push(
        `<line x1="${x}" y1="${top}" x2="${x}" y2="${symbolY}" stroke="#111" stroke-width="1.2" marker-start="url(#dot)"/>`
      );
      parts.push(this.deviceSymbol(device, x - 22, symbolY, 44));

      const infoY = symbolY + 46;
      parts.push(
        `<text x="${x}" y="${infoY}" font-size="9" font-weight="600" fill="#111" text-anchor="middle">${this.escape(
          device.label
        )}</text>`
      );
      parts.push(
        `<text x="${x}" y="${infoY + 10}" font-size="7.5" fill="#555" text-anchor="middle">${
          device.tripCurve ?? ''
        }${this.deviceRating(device)}</text>`
      );
      parts.push(
        `<text x="${x}" y="${infoY + 19}" font-size="6.5" fill="#999" text-anchor="middle">${this.escape(
          device.catalogNumber
        )}</text>`
      );

      // Outgoing cable with its cross section and wire marks.
      const cableTop = infoY + 26;
      const cableBottom = cableTop + 34;
      const conductors = circuit.conductors;
      conductors.forEach((c, ci) => {
        const cx = x - ((conductors.length - 1) * 3) / 2 + ci * 3;
        parts.push(
          `<line x1="${cx}" y1="${cableTop}" x2="${cx}" y2="${cableBottom}" stroke="${
            CONDUCTOR_COLORS[c]
          }" stroke-width="1.6"/>`
        );
      });
      parts.push(
        `<text x="${x}" y="${cableBottom + 11}" font-size="7.5" fill="#333" text-anchor="middle">${this.escape(
          circuit.cableType
        )}</text>`
      );
      parts.push(
        `<text x="${x}" y="${cableBottom + 20}" font-size="7" fill="#666" text-anchor="middle">Кръг ${
          circuit.number
        } · ${circuit.loadKw} kW</text>`
      );
      parts.push(
        `<text x="${x}" y="${cableBottom + 29}" font-size="6.5" fill="#999" text-anchor="middle">${this.escape(
          circuit.name
        )}</text>`
      );

      const wireMarks = design.wiring.wires
        .filter((w) => w.to.componentUid === device.uid && w.conductor !== 'PE')
        .map((w) => w.wireNumber);
      if (wireMarks.length) {
        parts.push(
          `<text x="${x + 24}" y="${symbolY + 6}" font-size="6.5" fill="#0277bd">${wireMarks
            .slice(0, 4)
            .join(',')}</text>`
        );
      }
    });

    return `<g>${parts.join('\n')}</g>`;
  }

  private deviceRating(device: PlacedComponent): string {
    const parts: string[] = [];
    if (device.ratedCurrentA) parts.push(`${device.ratedCurrentA}A`);
    if (device.poles) parts.push(`${device.poles}p`);
    if (device.residualCurrentA) parts.push(`${(device.residualCurrentA * 1000).toFixed(0)}mA`);
    return parts.join(' · ');
  }

  /** IEC 60617 style symbols for the device classes the editor supports. */
  private deviceSymbol(device: PlacedComponent, x: number, y: number, width: number): string {
    const category = (device.category ?? '').toUpperCase();
    const poles = device.poles ?? 1;
    const cx = x + width / 2;

    if (category.includes('RCBO')) {
      return this.rcboSymbol(cx, y, poles, device);
    }
    if (category === 'RCD') {
      return this.rcdSymbol(cx, y, poles);
    }
    if (category.includes('SPD')) {
      return this.spdSymbol(cx, y);
    }
    if (category.includes('CONTACTOR')) {
      return this.contactorSymbol(cx, y, poles);
    }
    if (category.includes('SWITCH')) {
      return this.switchSymbol(cx, y, poles);
    }
    return this.mcbSymbol(cx, y, poles, device.tripCurve ?? undefined);
  }

  /** Circuit breaker: switch blade with thermal and magnetic release marks. */
  private mcbSymbol(cx: number, y: number, poles: number, curve?: string): string {
    const h = 30;
    return `<g stroke="#111" fill="none" stroke-width="1.3">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 6}"/>
  <line x1="${cx}" y1="${y + 6}" x2="${cx + 9}" y2="${y + 16}"/>
  <line x1="${cx}" y1="${y + h}" x2="${cx}" y2="${y + h - 6}"/>
  <path d="M ${cx + 3} ${y + 20} q 5 3 0 6 q -5 3 0 6" stroke-width="1"/>
  <rect x="${cx - 9}" y="${y + 8}" width="6" height="8" fill="#111" stroke="none"/>
  ${
    poles > 1
      ? `<line x1="${cx - 14}" y1="${y + 11}" x2="${cx + 14}" y2="${
          y + 11
        }" stroke-dasharray="2 2" stroke-width="0.8"/>
  <text x="${cx - 20}" y="${y + 14}" font-size="7" fill="#666" stroke="none">${poles}</text>`
      : ''
  }
  ${curve ? `<text x="${cx + 12}" y="${y + 30}" font-size="7" fill="#666" stroke="none">${curve}</text>` : ''}
</g>`;
  }

  /** Residual current device: switch contacts plus the toroidal sensor. */
  private rcdSymbol(cx: number, y: number, poles: number): string {
    const h = 34;
    return `<g stroke="#111" fill="none" stroke-width="1.3">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 6}"/>
  <line x1="${cx}" y1="${y + 6}" x2="${cx + 9}" y2="${y + 16}"/>
  <line x1="${cx}" y1="${y + h}" x2="${cx}" y2="${y + h - 8}"/>
  <ellipse cx="${cx + 2}" cy="${y + 22}" rx="9" ry="5"/>
  <line x1="${cx - 14}" y1="${y + 11}" x2="${cx + 14}" y2="${y + 11}" stroke-dasharray="2 2" stroke-width="0.8"/>
  <text x="${cx - 22}" y="${y + 14}" font-size="7" fill="#666" stroke="none">${poles}</text>
</g>`;
  }

  /** RCBO: combined overcurrent release and residual sensor in one device. */
  private rcboSymbol(cx: number, y: number, poles: number, device: PlacedComponent): string {
    const h = 36;
    return `<g stroke="#111" fill="none" stroke-width="1.3">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 6}"/>
  <line x1="${cx}" y1="${y + 6}" x2="${cx + 9}" y2="${y + 16}"/>
  <line x1="${cx}" y1="${y + h}" x2="${cx}" y2="${y + h - 8}"/>
  <rect x="${cx - 9}" y="${y + 8}" width="6" height="8" fill="#111" stroke="none"/>
  <ellipse cx="${cx + 2}" cy="${y + 24}" rx="8" ry="4.5"/>
  <text x="${cx - 24}" y="${y + 14}" font-size="7" fill="#666" stroke="none">${poles}</text>
  ${
    device.residualCurrentA
      ? `<text x="${cx + 12}" y="${y + 27}" font-size="6.5" fill="#666" stroke="none">${(
          device.residualCurrentA * 1000
        ).toFixed(0)}mA</text>`
      : ''
  }
</g>`;
  }

  /** Surge protective device: varistor block between line and earth. */
  private spdSymbol(cx: number, y: number): string {
    return `<g stroke="#111" fill="none" stroke-width="1.3">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 8}"/>
  <rect x="${cx - 7}" y="${y + 8}" width="14" height="16"/>
  <line x1="${cx - 7}" y1="${y + 8}" x2="${cx + 7}" y2="${y + 24}" stroke-width="1"/>
  <line x1="${cx}" y1="${y + 24}" x2="${cx}" y2="${y + 32}"/>
  <line x1="${cx - 6}" y1="${y + 32}" x2="${cx + 6}" y2="${y + 32}" stroke-width="2"/>
  <line x1="${cx - 4}" y1="${y + 35}" x2="${cx + 4}" y2="${y + 35}" stroke-width="1.5"/>
  <line x1="${cx - 2}" y1="${y + 38}" x2="${cx + 2}" y2="${y + 38}" stroke-width="1"/>
</g>`;
  }

  /** Contactor: switch with the operating coil alongside. */
  private contactorSymbol(cx: number, y: number, poles: number): string {
    const h = 30;
    return `<g stroke="#111" fill="none" stroke-width="1.3">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 8}"/>
  <line x1="${cx}" y1="${y + 8}" x2="${cx + 9}" y2="${y + 18}"/>
  <path d="M ${cx + 1} ${y + 8} a 4 4 0 0 0 8 0" stroke-width="1"/>
  <line x1="${cx}" y1="${y + h}" x2="${cx}" y2="${y + 20}"/>
  <rect x="${cx + 14}" y="${y + 10}" width="10" height="10"/>
  <text x="${cx - 20}" y="${y + 16}" font-size="7" fill="#666" stroke="none">${poles}</text>
</g>`;
  }

  /** Isolating switch: plain switch blade, no releases. */
  private switchSymbol(cx: number, y: number, poles: number): string {
    const h = 30;
    return `<g stroke="#111" fill="none" stroke-width="1.4">
  <line x1="${cx}" y1="${y}" x2="${cx}" y2="${y + 8}"/>
  <line x1="${cx}" y1="${y + 8}" x2="${cx + 10}" y2="${y + 20}"/>
  <line x1="${cx}" y1="${y + h}" x2="${cx}" y2="${y + 22}"/>
  <line x1="${cx - 5}" y1="${y + 22}" x2="${cx + 5}" y2="${y + 22}" stroke-width="1"/>
  <line x1="${cx - 14}" y1="${y + 14}" x2="${cx + 14}" y2="${y + 14}" stroke-dasharray="2 2" stroke-width="0.8"/>
  <text x="${cx - 22}" y="${y + 17}" font-size="7" fill="#666" stroke="none">${poles}</text>
</g>`;
  }

  private escape(value: string): string {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
}
