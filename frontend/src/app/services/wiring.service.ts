import { Injectable } from '@angular/core';
import {
  BusbarRun,
  CONDUCTOR_COLORS,
  Circuit,
  Conductor,
  Connection,
  DesignData,
  DistributionBar,
  formatCableType,
  PlacedComponent,
  Terminal,
  Wire,
  WiringData,
} from '../models/project.models';

/** Physical width of one DIN module. */
const MODULE_WIDTH_MM = 17.5;

/** Vertical pitch between DIN rails inside an ETIBOX enclosure. */
const ROW_PITCH_MM = 125;

/**
 * Cross sections available for the internal wiring, paired with the largest
 * breaker rating each one may be used for (copper, PVC, inside an enclosure).
 * Based on the current-carrying capacity tables of IEC 60364-5-52 reference
 * method B, derated for enclosure temperature.
 */
const CROSS_SECTION_LADDER: ReadonlyArray<{ mm2: number; maxCurrentA: number }> = [
  { mm2: 1.5, maxCurrentA: 16 },
  { mm2: 2.5, maxCurrentA: 20 },
  { mm2: 4, maxCurrentA: 25 },
  { mm2: 6, maxCurrentA: 32 },
  { mm2: 10, maxCurrentA: 50 },
  { mm2: 16, maxCurrentA: 63 },
  { mm2: 25, maxCurrentA: 80 },
  { mm2: 35, maxCurrentA: 100 },
  { mm2: 50, maxCurrentA: 125 },
  { mm2: 70, maxCurrentA: 160 },
];

export function crossSectionForCurrent(currentA: number): number {
  const match = CROSS_SECTION_LADDER.find((entry) => currentA <= entry.maxCurrentA);
  return match?.mm2 ?? CROSS_SECTION_LADDER[CROSS_SECTION_LADDER.length - 1].mm2;
}

/** Comb busbars available in the catalog, keyed by phase count. */
const BUSBAR_PARTS: Record<1 | 3, { catalogNumber: string; name: string; modules: number; ratedCurrentA: number }> = {
  1: { catalogNumber: '002921100', name: 'IZS10/1F/12 гребенна шина 1F', modules: 12, ratedCurrentA: 63 },
  3: { catalogNumber: '002921101', name: 'IZS10/3F/12 гребенна шина 3F', modules: 12, ratedCurrentA: 63 },
};

const N_BAR = { catalogNumber: '003901012', name: 'Клема N шина 12x 16mm²', ways: 12 };
const PE_BAR = { catalogNumber: '003901013', name: 'Клема PE шина 12x 16mm²', ways: 12 };

const THREE_PHASE: ReadonlyArray<'L1' | 'L2' | 'L3'> = ['L1', 'L2', 'L3'];

export type FeedNode = {
  feedFromUid: string | null;
  conductors: Conductor[];
  role: NonNullable<PlacedComponent['role']>;
};

export type FeedTree = Map<string, FeedNode>;

/**
 * Derives the electrical wiring of a panel from its physical layout.
 *
 * The generator walks the layout row by row: the device with the highest
 * rating and no upstream feed becomes the incoming device, RCDs and switches
 * become distribution nodes, and every remaining protective device is treated
 * as an outgoing circuit. Adjacent single-pole devices sharing one feed are
 * collapsed into comb busbar runs, which is how such panels are actually built.
 */
@Injectable({ providedIn: 'root' })
export class WiringService {
  generate(design: DesignData): WiringData {
    return this.materialize(design, this.inferTree(design));
  }

  /**
   * Rebuilds wires and busbars from the current device positions while keeping
   * any feed relationships the designer has already set.
   */
  reroute(design: DesignData): WiringData {
    return this.materialize(design, this.resolveTree(design));
  }

  inferTree(design: DesignData): FeedTree {
    const components = this.sorted(design.components);
    const incoming = this.pickIncoming(components);
    return this.buildFeedTree(components, incoming, design.enclosure.phases ?? 3);
  }

  /**
   * Stored feeds win. Devices that were added later (no feedFromUid yet) take
   * the automatic assignment when `inferMissing` is true, so a new MCB still
   * gets wired when the designer presses Окабеляване.
   */
  resolveTree(design: DesignData, inferMissing = true): FeedTree {
    const inferred = inferMissing ? this.inferTree(design) : null;
    const tree: FeedTree = new Map();
    const known = new Set(design.components.map((c) => c.uid));

    for (const comp of design.components) {
      if (comp.feedFromUid === undefined) {
        const node = inferred?.get(comp.uid);
        if (node) tree.set(comp.uid, node);
        continue;
      }

      const inferredNode = inferred?.get(comp.uid);
      const feed =
        comp.feedFromUid === null || known.has(comp.feedFromUid)
          ? comp.feedFromUid
          : (inferredNode?.feedFromUid ?? null);
      tree.set(comp.uid, {
        feedFromUid: feed,
        conductors: comp.phases?.length ? comp.phases : inferredNode?.conductors ?? ['L1'],
        role: comp.role ?? inferredNode?.role ?? 'protection',
      });
    }

    return tree;
  }

  materialize(design: DesignData, tree: FeedTree): WiringData {
    const components = this.sorted(design.components);
    const incoming = [...tree.entries()].find(([, node]) => node.role === 'incoming');
    const busbars = this.buildBusbars(tree, components);
    const wires = this.buildWires(tree, components, busbars, design);
    const circuits = this.buildCircuits(tree, components);
    const bars = this.buildBars(tree, components);

    return {
      wires,
      busbars,
      bars,
      circuits,
      incomingUid: incoming?.[0] ?? this.pickIncoming(components)?.uid ?? null,
      generatedAt: new Date().toISOString(),
    };
  }

  stampComponents(components: PlacedComponent[], tree: FeedTree): PlacedComponent[] {
    return components.map((comp) => {
      const node = tree.get(comp.uid);
      if (!node) return comp;
      return {
        ...comp,
        feedFromUid: node.feedFromUid,
        phases: node.conductors,
        role: node.role,
      };
    });
  }

  connectionsFromTree(tree: FeedTree): Connection[] {
    const connections: Connection[] = [];
    for (const [uid, node] of tree) {
      if (!node.feedFromUid) continue;
      connections.push({ from: node.feedFromUid, to: uid, type: 'feed' });
    }
    return connections;
  }

  private sorted(components: PlacedComponent[]): PlacedComponent[] {
    return [...components].sort((a, b) => a.row - b.row || a.startModule - b.startModule);
  }

  /**
   * The incoming device is the isolator or highest-rated multi-pole device on
   * the first row; panels are built with the supply entering top-left.
   */
  private pickIncoming(components: PlacedComponent[]): PlacedComponent | null {
    const candidates = components.filter((c) => this.isSwitchOrIsolator(c) || (c.poles ?? 1) >= 3);
    const pool = candidates.length ? candidates : components;
    return (
      [...pool].sort(
        (a, b) =>
          a.row - b.row ||
          (b.ratedCurrentA ?? 0) - (a.ratedCurrentA ?? 0) ||
          a.startModule - b.startModule
      )[0] ?? null
    );
  }

  /**
   * Assigns each device an upstream feeder and a set of conductors.
   *
   * Two feed points are tracked. The *main* feeder is the incoming device or the
   * last isolator, and it supplies everything that sits directly on the main
   * busbar: surge arresters and the residual current devices themselves. The
   * *group* feeder is the most recent residual current device, and it supplies
   * the protective devices that follow it.
   *
   * Keeping these apart matters: residual current devices are parallel branches
   * off the main busbar, so chaining one behind another would both misrepresent
   * the circuit and produce bogus selectivity errors.
   *
   * Single-pole devices are spread across L1/L2/L3 to keep the phases balanced.
   */
  private buildFeedTree(
    components: PlacedComponent[],
    incoming: PlacedComponent | null,
    supplyPhases: 1 | 3
  ): FeedTree {
    const tree: FeedTree = new Map();
    const phaseLoad: Record<'L1' | 'L2' | 'L3', number> = { L1: 0, L2: 0, L3: 0 };

    const groupLoad = this.groupLoads(components);

    let mainFeeder: PlacedComponent | null = incoming;
    let groupFeeder: PlacedComponent | null = null;

    for (const comp of components) {
      if (incoming && comp.uid === incoming.uid) {
        tree.set(comp.uid, {
          feedFromUid: null,
          conductors: this.supplyConductors(supplyPhases, comp),
          role: 'incoming',
        });
        mainFeeder = comp;
        continue;
      }

      const poles = comp.poles ?? 1;
      const isRcd = this.isRcd(comp);
      const isIsolator = this.isSwitchOrIsolator(comp);

      // Surge arresters, isolators and RCDs hang off the main busbar; anything
      // else takes its supply from the RCD group it belongs to.
      const feeder =
        isRcd || isIsolator || this.isSpd(comp) ? mainFeeder : groupFeeder ?? mainFeeder;
      const feederConductors = feeder ? tree.get(feeder.uid)?.conductors ?? [] : [];

      let conductors: Conductor[];
      if (poles >= 3) {
        conductors = poles >= 4 ? [...THREE_PHASE, 'N'] : [...THREE_PHASE];
      } else if (this.isSpd(comp)) {
        conductors = poles >= 4 ? [...THREE_PHASE, 'N'] : ['L1', 'N'];
      } else {
        // Single-phase device: take the least loaded phase available upstream.
        const available = this.availablePhases(feederConductors, supplyPhases);
        const phase = available.reduce(
          (best, p) => (phaseLoad[p] < phaseLoad[best] ? p : best),
          available[0]
        );

        // Only book load once per phase. A single-phase RCD reserves the whole
        // group that follows it, and those devices then inherit its phase
        // without adding their ratings again.
        if (available.length > 1) {
          phaseLoad[phase] += isRcd
            ? groupLoad.get(comp.uid) ?? comp.ratedCurrentA ?? 0
            : comp.ratedCurrentA ?? 0;
        }

        conductors = poles >= 2 ? [phase, 'N'] : [phase];
      }

      const role: FeedNode['role'] = isRcd || isIsolator ? 'distribution' : this.roleFor(comp);
      tree.set(comp.uid, { feedFromUid: feeder?.uid ?? null, conductors, role });

      if (isIsolator) {
        // A further isolator becomes the new main feed point for what follows.
        mainFeeder = comp;
        groupFeeder = null;
      } else if (isRcd) {
        groupFeeder = comp;
      }
    }

    return tree;
  }

  /**
   * Total rating of the single-phase circuits that follow each residual current
   * device, up to the next distribution device. A single-phase RCD carries all
   * of that load on one phase, so the balancer has to know the figure before it
   * chooses which phase to put the group on.
   */
  private groupLoads(components: PlacedComponent[]): Map<string, number> {
    const loads = new Map<string, number>();

    components.forEach((comp, index) => {
      if (!this.isRcd(comp)) return;

      let sum = 0;
      for (let i = index + 1; i < components.length; i++) {
        const next = components[i];
        if (this.isRcd(next) || this.isSwitchOrIsolator(next)) break;
        if (!this.isProtective(next)) continue;
        if ((next.poles ?? 1) >= 3) continue;
        sum += next.ratedCurrentA ?? 0;
      }
      loads.set(comp.uid, sum || (comp.ratedCurrentA ?? 0));
    });

    return loads;
  }

  private supplyConductors(supplyPhases: 1 | 3, incoming: PlacedComponent): Conductor[] {
    const poles = incoming.poles ?? (supplyPhases === 3 ? 4 : 2);
    if (supplyPhases === 1 || poles <= 2) return ['L1', 'N', 'PE'];
    return [...THREE_PHASE, 'N', 'PE'];
  }

  private availablePhases(feederConductors: Conductor[], supplyPhases: 1 | 3): Array<'L1' | 'L2' | 'L3'> {
    const upstream = feederConductors.filter((c): c is 'L1' | 'L2' | 'L3' =>
      c === 'L1' || c === 'L2' || c === 'L3'
    );
    if (upstream.length) return upstream;
    return supplyPhases === 3 ? [...THREE_PHASE] : ['L1'];
  }

  /**
   * Groups runs of adjacent devices that share one feeder into comb busbars.
   * A run needs at least two devices to be worth a busbar; shorter runs stay
   * as discrete wires.
   */
  private buildBusbars(tree: FeedTree, components: PlacedComponent[]): BusbarRun[] {
    const busbars: BusbarRun[] = [];
    const byRow = new Map<number, PlacedComponent[]>();
    for (const comp of components) {
      if (!byRow.has(comp.row)) byRow.set(comp.row, []);
      byRow.get(comp.row)!.push(comp);
    }

    let index = 0;
    for (const [row, rowComponents] of [...byRow.entries()].sort((a, b) => a[0] - b[0])) {
      const ordered = [...rowComponents].sort((a, b) => a.startModule - b.startModule);
      let run: PlacedComponent[] = [];
      let runFeeder: string | null = null;

      const flush = () => {
        if (run.length >= 2 && runFeeder) {
          const phases = this.runPhaseCount(run, tree);
          const part = BUSBAR_PARTS[phases];
          const start = run[0].startModule;
          const end = run[run.length - 1].startModule + run[run.length - 1].widthModules;
          index += 1;
          busbars.push({
            id: `bb${index}`,
            catalogNumber: part.catalogNumber,
            name: part.name,
            row,
            startModule: start,
            spanModules: end - start,
            phases,
            connectionType: 'PIN',
            sourceUid: runFeeder,
            targetUids: run.map((c) => c.uid),
            ratedCurrentA: part.ratedCurrentA,
          });
        }
        run = [];
        runFeeder = null;
      };

      for (const comp of ordered) {
        const node = tree.get(comp.uid);
        const eligible =
          node &&
          node.role !== 'incoming' &&
          node.role !== 'distribution' &&
          this.isProtective(comp) &&
          (comp.poles ?? 1) <= 4;

        if (!eligible) {
          flush();
          continue;
        }

        const feeder = node!.feedFromUid;
        const adjacent =
          run.length === 0 ||
          run[run.length - 1].startModule + run[run.length - 1].widthModules === comp.startModule;

        if (run.length && (feeder !== runFeeder || !adjacent)) {
          flush();
        }
        if (!run.length) runFeeder = feeder;
        run.push(comp);
      }
      flush();
    }

    return busbars;
  }

  private runPhaseCount(
    run: PlacedComponent[],
    tree: Map<string, { conductors: Conductor[] }>
  ): 1 | 3 {
    const phases = new Set<string>();
    for (const comp of run) {
      for (const c of tree.get(comp.uid)?.conductors ?? []) {
        if (c === 'L1' || c === 'L2' || c === 'L3') phases.add(c);
      }
    }
    return phases.size > 1 ? 3 : 1;
  }

  private buildWires(
    tree: FeedTree,
    components: PlacedComponent[],
    busbars: BusbarRun[],
    design: DesignData
  ): Wire[] {
    const byUid = new Map(components.map((c) => [c.uid, c]));
    const busbarByTarget = new Map<string, BusbarRun>();
    for (const bb of busbars) {
      for (const uid of bb.targetUids) busbarByTarget.set(uid, bb);
    }

    const wires: Wire[] = [];
    let seq = 0;
    const nextNumber = () => {
      seq += 1;
      return `W${String(seq).padStart(3, '0')}`;
    };

    // Feed wires from each busbar's source into the busbar itself.
    const wiredBusbars = new Set<string>();
    for (const bb of busbars) {
      const source = byUid.get(bb.sourceUid);
      if (!source || wiredBusbars.has(bb.id)) continue;
      wiredBusbars.add(bb.id);

      const target = byUid.get(bb.targetUids[0]);
      if (!target) continue;
      const phases: Conductor[] = bb.phases === 3 ? [...THREE_PHASE] : ['L1'];
      const current = source.ratedCurrentA ?? bb.ratedCurrentA;

      phases.forEach((conductor, i) => {
        wires.push({
          id: `w-bb-${bb.id}-${conductor}`,
          wireNumber: nextNumber(),
          from: { componentUid: source.uid, side: 'bottom', pole: i + 1, conductor },
          to: { componentUid: target.uid, side: 'top', pole: i + 1, conductor },
          conductor,
          crossSectionMm2: this.crossSectionFor(current),
          colorHex: CONDUCTOR_COLORS[conductor],
          lengthMm: this.estimateLength(source, target, design),
          viaBusbarId: bb.id,
          note: `Захранване на ${bb.name}`,
        });
      });
    }

    // Discrete wires for devices not covered by a busbar.
    for (const comp of components) {
      const node = tree.get(comp.uid);
      if (!node || !node.feedFromUid) continue;
      if (busbarByTarget.has(comp.uid)) continue;

      const source = byUid.get(node.feedFromUid);
      if (!source) continue;
      const current = comp.ratedCurrentA ?? source.ratedCurrentA ?? 16;

      node.conductors
        .filter((c) => c !== 'PE')
        .forEach((conductor, i) => {
          wires.push({
            id: `w-${comp.uid}-${conductor}`,
            wireNumber: nextNumber(),
            from: { componentUid: source.uid, side: 'bottom', pole: i + 1, conductor },
            to: { componentUid: comp.uid, side: 'top', pole: i + 1, conductor },
            conductor,
            crossSectionMm2: this.crossSectionFor(current),
            colorHex: CONDUCTOR_COLORS[conductor],
            lengthMm: this.estimateLength(source, comp, design),
          });
        });
    }

    // PE wires from the PE bar to every device that needs an earth connection.
    for (const comp of components) {
      if (!tree.has(comp.uid) || comp.omitPeDrop || !this.needsPe(comp)) continue;
      wires.push({
        id: `w-pe-${comp.uid}`,
        wireNumber: nextNumber(),
        from: { componentUid: 'PE-BAR', side: 'bottom', pole: 1, conductor: 'PE' },
        to: { componentUid: comp.uid, side: 'bottom', pole: 1, conductor: 'PE' },
        conductor: 'PE',
        crossSectionMm2: this.crossSectionFor(comp.ratedCurrentA ?? 16),
        colorHex: CONDUCTOR_COLORS.PE,
        lengthMm: this.estimateLength(comp, comp, design) + ROW_PITCH_MM,
        note: 'Защитно заземяване',
      });
    }

    // Neutral drops from devices that carry N onto the N distribution bar.
    for (const comp of components) {
      const node = tree.get(comp.uid);
      if (!node || comp.omitNeutralDrop || !node.conductors.includes('N')) continue;
      wires.push({
        id: `w-n-${comp.uid}`,
        wireNumber: nextNumber(),
        from: { componentUid: 'N-BAR', side: 'bottom', pole: 1, conductor: 'N' },
        to: { componentUid: comp.uid, side: 'bottom', pole: 1, conductor: 'N' },
        conductor: 'N',
        crossSectionMm2: this.crossSectionFor(comp.ratedCurrentA ?? 16),
        colorHex: CONDUCTOR_COLORS.N,
        lengthMm: this.estimateLength(comp, comp, design) + ROW_PITCH_MM,
        note: 'N шина',
      });
    }

    return wires;
  }

  private buildCircuits(tree: FeedTree, components: PlacedComponent[]): Circuit[] {
    const circuits: Circuit[] = [];
    let number = 0;

    for (const comp of components) {
      const node = tree.get(comp.uid);
      if (!node || node.role === 'incoming' || node.role === 'distribution') continue;
      if (!this.isProtective(comp)) continue;

      number += 1;
      const conductors = node.conductors;
      const isThreePhase = conductors.filter((c) => c.startsWith('L')).length > 1;
      const current = comp.ratedCurrentA ?? 16;
      const voltage = isThreePhase ? 400 : 230;
      const powerFactor = 0.95;
      const loadKw = (isThreePhase ? Math.sqrt(3) : 1) * voltage * current * powerFactor * 0.8 / 1000;
      const mm2 = this.crossSectionFor(current);

      circuits.push({
        id: `cir${number}`,
        number: String(number),
        name: `Кръг ${number} (${comp.label})`,
        description: comp.name,
        protectiveDeviceUid: comp.uid,
        conductors,
        loadKw: Math.round(loadKw * 100) / 100,
        powerFactor,
        cableKind: 'NYM-J',
        cableType: formatCableType('NYM-J', conductors, mm2),
        cableCrossSectionMm2: mm2,
        lengthM: 20,
        voltageDropPercent: this.voltageDrop(current, 20, mm2, isThreePhase),
      });
    }

    return circuits;
  }

  private buildBars(tree: FeedTree, components: PlacedComponent[]): DistributionBar[] {
    let nWays = 0;
    let peWays = 0;
    for (const comp of components) {
      const node = tree.get(comp.uid);
      if (node?.conductors.includes('N') && !comp.omitNeutralDrop) nWays += 1;
      if (tree.has(comp.uid) && this.needsPe(comp) && !comp.omitPeDrop) peWays += 1;
    }

    const bars: DistributionBar[] = [];
    if (nWays > 0) {
      bars.push({
        id: 'bar-n',
        catalogNumber: N_BAR.catalogNumber,
        name: N_BAR.name,
        conductor: 'N',
        ways: Math.max(N_BAR.ways, Math.ceil(nWays / N_BAR.ways) * N_BAR.ways),
        usedWays: nWays,
      });
    }
    if (peWays > 0) {
      bars.push({
        id: 'bar-pe',
        catalogNumber: PE_BAR.catalogNumber,
        name: PE_BAR.name,
        conductor: 'PE',
        ways: Math.max(PE_BAR.ways, Math.ceil(peWays / PE_BAR.ways) * PE_BAR.ways),
        usedWays: peWays,
      });
    }
    return bars;
  }

  /** Smallest cross section from the ladder that carries the given current. */
  crossSectionFor(currentA: number): number {
    return crossSectionForCurrent(currentA);
  }

  /**
   * Voltage drop in percent for a copper cable, using the standard
   * ΔU = k · L · I / (γ · S) approximation with γ = 56 m/(Ω·mm²).
   */
  voltageDrop(currentA: number, lengthM: number, mm2: number, threePhase: boolean): number {
    const conductivity = 56;
    const voltage = threePhase ? 400 : 230;
    const factor = threePhase ? Math.sqrt(3) : 2;
    const drop = (factor * lengthM * currentA) / (conductivity * mm2);
    return Math.round(((drop / voltage) * 100) * 100) / 100;
  }

  /**
   * Manhattan routing estimate through the wiring channels: horizontal travel
   * along the rail plus vertical travel between rails, with slack for bending.
   */
  private estimateLength(from: PlacedComponent, to: PlacedComponent, design: DesignData): number {
    const fromCentre = (from.startModule + from.widthModules / 2) * MODULE_WIDTH_MM;
    const toCentre = (to.startModule + to.widthModules / 2) * MODULE_WIDTH_MM;
    const horizontal = Math.abs(fromCentre - toCentre);
    const vertical = Math.abs(from.row - to.row) * ROW_PITCH_MM + 60;
    const slack = 1.25;
    void design;
    return Math.round((horizontal + vertical) * slack);
  }

  private isProtective(comp: PlacedComponent): boolean {
    const category = (comp.category ?? '').toUpperCase();
    return ['MCB', 'MCCB', 'RCBO'].some((c) => category.includes(c));
  }

  private isRcd(comp: PlacedComponent): boolean {
    return (comp.category ?? '').toUpperCase() === 'RCD';
  }

  private isSwitchOrIsolator(comp: PlacedComponent): boolean {
    return (comp.category ?? '').toUpperCase().includes('SWITCH');
  }

  private isSpd(comp: PlacedComponent): boolean {
    return (comp.category ?? '').toUpperCase().includes('SPD');
  }

  private needsPe(comp: PlacedComponent): boolean {
    const category = (comp.category ?? '').toUpperCase();
    return (
      category.includes('SPD') ||
      category.includes('RCD') ||
      category.includes('RCBO') ||
      category.includes('MCB') ||
      category.includes('MCCB') ||
      category.includes('SWITCH')
    );
  }

  private roleFor(comp: PlacedComponent): NonNullable<PlacedComponent['role']> {
    const category = (comp.category ?? '').toUpperCase();
    if (category.includes('CONTACTOR')) return 'auxiliary';
    if (category.includes('SPD')) return 'auxiliary';
    if (this.isProtective(comp)) return 'protection';
    return 'load';
  }
}
