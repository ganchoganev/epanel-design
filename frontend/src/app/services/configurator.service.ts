import { Injectable, inject } from '@angular/core';
import { EtiProduct } from '../models/catalog.models';
import { Conductor } from '../models/project.models';
import { WiringService } from './wiring.service';

export type LoadKind =
  | 'lighting'
  | 'sockets'
  | 'kitchen'
  | 'boiler'
  | 'ac'
  | 'oven'
  | 'motor'
  | 'ev_charger'
  | 'other';

export interface LoadSpec {
  id: string;
  name: string;
  kind: LoadKind;
  powerKw: number;
  /** Number of identical circuits for this load. */
  quantity: number;
  threePhase: boolean;
  /** Cable run length to the load, used for the voltage drop check. */
  lengthM: number;
}

export interface ConfiguratorOptions {
  /** Supply phases available at the board. */
  supplyPhases: 1 | 3;
  /** Diversity factor applied to the sum of circuit currents. */
  diversityFactor: number;
  /** Use one RCBO per circuit instead of RCD groups. */
  perCircuitRcbo: boolean;
  /** Include an ETITEC surge protective device. */
  includeSpd: boolean;
  /** Include a main isolating switch. */
  includeMainSwitch: boolean;
  /** Spare modules to leave free for later additions. */
  spareModules: number;
}

export interface ConfiguredDevice {
  catalogNumber: string;
  label: string;
  purpose: string;
  offsetModule: number;
  widthModules: number;
  loadId?: string;
}

export interface ConfiguratorResult {
  devices: ConfiguredDevice[];
  enclosure: { catalogNumber: string; name: string; rows: number; modulesPerRow: number } | null;
  totalModules: number;
  mainBreakerCurrentA: number;
  phaseLoadA: Record<'L1' | 'L2' | 'L3', number>;
  notes: string[];
  unresolved: string[];
}

/** Standard MCB ratings ETI offers, used when sizing a circuit. */
const MCB_RATINGS = [6, 10, 13, 16, 20, 25, 32, 40, 50, 63];

/** Main switch ratings available in the catalog. */
const MAIN_SWITCH_RATINGS = [40, 63];

/**
 * Per-load-type engineering defaults. `curve` follows the usual practice:
 * B for resistive and lighting loads, C where inrush current is expected.
 * `diversity` is the demand factor applied when sizing the incoming device.
 */
const LOAD_PROFILES: Record<
  LoadKind,
  { curve: 'B' | 'C'; rcdSensitivityA: number; diversity: number; dedicatedRcd: boolean; label: string }
> = {
  lighting: { curve: 'B', rcdSensitivityA: 0.03, diversity: 0.9, dedicatedRcd: false, label: 'Осветление' },
  sockets: { curve: 'B', rcdSensitivityA: 0.03, diversity: 0.6, dedicatedRcd: false, label: 'Контакти' },
  kitchen: { curve: 'C', rcdSensitivityA: 0.03, diversity: 0.7, dedicatedRcd: false, label: 'Кухня' },
  boiler: { curve: 'C', rcdSensitivityA: 0.03, diversity: 1.0, dedicatedRcd: true, label: 'Бойлер' },
  ac: { curve: 'C', rcdSensitivityA: 0.03, diversity: 0.8, dedicatedRcd: true, label: 'Климатик' },
  oven: { curve: 'C', rcdSensitivityA: 0.03, diversity: 0.8, dedicatedRcd: true, label: 'Фурна/плот' },
  motor: { curve: 'C', rcdSensitivityA: 0.3, diversity: 1.0, dedicatedRcd: true, label: 'Двигател' },
  ev_charger: { curve: 'C', rcdSensitivityA: 0.03, diversity: 1.0, dedicatedRcd: true, label: 'Зарядна станция' },
  other: { curve: 'C', rcdSensitivityA: 0.03, diversity: 0.8, dedicatedRcd: false, label: 'Друго' },
};

/** Maximum circuits allowed behind one 30 mA RCD before splitting the group. */
const MAX_CIRCUITS_PER_RCD = 6;

/**
 * Turns a list of loads into a complete panel: sizes each circuit's breaker,
 * groups circuits behind residual current devices, picks the incoming device
 * and finally selects the smallest ETIBOX enclosure that fits everything.
 *
 * The selection only uses catalog numbers that exist in the passed product
 * catalog, so the result is always orderable. Anything that cannot be matched
 * is reported in `unresolved` rather than silently substituted.
 */
@Injectable({ providedIn: 'root' })
export class ConfiguratorService {
  private wiring = inject(WiringService);

  configure(
    loads: LoadSpec[],
    options: ConfiguratorOptions,
    catalog: EtiProduct[]
  ): ConfiguratorResult {
    const index = this.buildIndex(catalog);
    const devices: ConfiguredDevice[] = [];
    const notes: string[] = [];
    const unresolved: string[] = [];
    const phaseLoad: Record<'L1' | 'L2' | 'L3', number> = { L1: 0, L2: 0, L3: 0 };

    let offset = 0;
    let labelCounters = { F: 0, FI: 0, Q: 0, SPD: 0, K: 0 };
    const nextLabel = (prefix: keyof typeof labelCounters) => {
      labelCounters[prefix] += 1;
      return `${prefix}${labelCounters[prefix]}`;
    };

    // Incoming device, sized after the circuits are known — reserve its slot.
    const mainSwitchSlot = options.includeMainSwitch ? offset : -1;
    if (options.includeMainSwitch) {
      offset += options.supplyPhases === 3 ? 4 : 2;
    }

    if (options.includeSpd) {
      const spd = options.supplyPhases === 3 ? index.spd4 : index.spd1;
      if (spd) {
        devices.push({
          catalogNumber: spd.catalog_number,
          label: nextLabel('SPD'),
          purpose: 'Катоден отводител T1+T2',
          offsetModule: offset,
          widthModules: spd.width_modules,
        });
        offset += spd.width_modules;
      } else {
        unresolved.push('Катоден отводител ETITEC не е намерен в каталога.');
      }
    }

    // Expand every load into individual circuits with a sized breaker.
    interface PlannedCircuit {
      load: LoadSpec;
      product: EtiProduct;
      currentA: number;
      dedicatedRcd: boolean;
      rcdSensitivityA: number;
      threePhase: boolean;
    }
    const planned: PlannedCircuit[] = [];

    for (const load of loads) {
      const profile = LOAD_PROFILES[load.kind];
      const threePhase = load.threePhase && options.supplyPhases === 3;
      const perCircuitKw = load.powerKw / Math.max(1, load.quantity);
      const currentA = this.currentFor(perCircuitKw, threePhase);
      const rating = this.pickRating(currentA);
      const poles = threePhase ? 3 : 1;

      const product = options.perCircuitRcbo && !threePhase
        ? index.rcbo(rating, profile.curve)
        : index.mcb(rating, poles, profile.curve);

      if (!product) {
        unresolved.push(
          `Няма подходящ апарат за „${load.name}“ (${rating} A, ${poles}p, крива ${profile.curve}).`
        );
        continue;
      }

      // The catalog may not stock the calculated size; the lookup steps up to
      // the next one, which the designer has to know about because the cable
      // must then be sized for the larger breaker.
      const actualRating = Number(product.rated_current_a ?? rating);
      if (actualRating !== rating) {
        notes.push(
          `„${load.name}“: изчислени ${rating} A, но в каталога е наличен ${actualRating} A (${product.catalog_number}) — оразмерете кабела за ${actualRating} A.`
        );
      }

      for (let i = 0; i < Math.max(1, load.quantity); i++) {
        planned.push({
          load,
          product,
          currentA: actualRating,
          dedicatedRcd: profile.dedicatedRcd && !options.perCircuitRcbo,
          rcdSensitivityA: profile.rcdSensitivityA,
          threePhase,
        });
      }
    }

    // Circuits that need their own RCD, then the pooled ones in groups.
    const dedicated = planned.filter((p) => p.dedicatedRcd);
    const pooled = planned.filter((p) => !p.dedicatedRcd);

    const placeCircuit = (circuit: PlannedCircuit) => {
      const label = nextLabel('F');
      devices.push({
        catalogNumber: circuit.product.catalog_number,
        label,
        purpose: `${LOAD_PROFILES[circuit.load.kind].label} — ${circuit.load.name}`,
        offsetModule: offset,
        widthModules: circuit.product.width_modules,
        loadId: circuit.load.id,
      });
      offset += circuit.product.width_modules;

      const phases: Array<'L1' | 'L2' | 'L3'> = circuit.threePhase
        ? ['L1', 'L2', 'L3']
        : [this.leastLoadedPhase(phaseLoad, options.supplyPhases)];
      const share = circuit.currentA / phases.length;
      for (const p of phases) phaseLoad[p] += share;
    };

    for (const circuit of dedicated) {
      if (!options.perCircuitRcbo) {
        const rcd = index.rcd(
          this.pickRcdRating(circuit.currentA),
          circuit.threePhase ? 4 : 2,
          circuit.rcdSensitivityA
        );
        if (rcd) {
          devices.push({
            catalogNumber: rcd.catalog_number,
            label: nextLabel('FI'),
            purpose: `Дефектнотокова защита за ${circuit.load.name}`,
            offsetModule: offset,
            widthModules: rcd.width_modules,
          });
          offset += rcd.width_modules;

          const actual = Number(rcd.residual_current_a ?? circuit.rcdSensitivityA);
          if (Math.abs(actual - circuit.rcdSensitivityA) > 0.0001) {
            notes.push(
              `„${circuit.load.name}“: препоръчани ${(circuit.rcdSensitivityA * 1000).toFixed(
                0
              )} mA, поставен е ${(actual * 1000).toFixed(0)} mA (${rcd.catalog_number}) — следете за неоправдани изключвания от утечен ток.`
            );
          }
        } else {
          unresolved.push(`Няма RCD за ${circuit.load.name}.`);
        }
      }
      placeCircuit(circuit);
    }

    for (let i = 0; i < pooled.length; i += MAX_CIRCUITS_PER_RCD) {
      const group = pooled.slice(i, i + MAX_CIRCUITS_PER_RCD);
      if (!options.perCircuitRcbo) {
        const groupCurrent = group.reduce((sum, c) => sum + c.currentA, 0) * 0.6;
        const anyThreePhase = group.some((c) => c.threePhase);
        const rcd = index.rcd(
          this.pickRcdRating(groupCurrent),
          anyThreePhase || options.supplyPhases === 3 ? 4 : 2,
          0.03
        );
        if (rcd) {
          devices.push({
            catalogNumber: rcd.catalog_number,
            label: nextLabel('FI'),
            purpose: `Дефектнотокова защита за ${group.length} кръга`,
            offsetModule: offset,
            widthModules: rcd.width_modules,
          });
          offset += rcd.width_modules;
        } else {
          unresolved.push('Няма подходящ групов RCD.');
        }
      }
      for (const circuit of group) placeCircuit(circuit);
    }

    // Size and insert the incoming device now that the total load is known.
    const peakPhase = Math.max(phaseLoad.L1, phaseLoad.L2, phaseLoad.L3);
    const demand = peakPhase * options.diversityFactor;
    const largestMainSwitch = MAIN_SWITCH_RATINGS[MAIN_SWITCH_RATINGS.length - 1];
    const mainCurrent = MAIN_SWITCH_RATINGS.find((r) => r >= demand) ?? largestMainSwitch;

    if (demand > largestMainSwitch) {
      unresolved.push(
        `Изчисленото потребление ${demand.toFixed(
          0
        )} A надвишава най-големия модулен прекъсвач ${largestMainSwitch} A. Нужен е автомат в лят корпус (MCCB) или разделяне на две табла.`
      );
    }

    if (options.includeMainSwitch && mainSwitchSlot >= 0) {
      const mainSwitch = index.mainSwitch(mainCurrent, options.supplyPhases === 3 ? 4 : 2);
      if (mainSwitch) {
        devices.unshift({
          catalogNumber: mainSwitch.catalog_number,
          label: 'Q1',
          purpose: `Главен прекъсвач ${mainCurrent} A`,
          offsetModule: mainSwitchSlot,
          widthModules: mainSwitch.width_modules,
        });
      } else {
        unresolved.push(`Няма главен прекъсвач ${mainCurrent} A в каталога.`);
      }
    }

    const totalModules = offset + options.spareModules;
    const enclosure = this.pickEnclosure(totalModules, catalog);
    if (!enclosure) {
      unresolved.push(
        `Няма ETIBOX табло за ${totalModules} модула. Разделете инсталацията на две табла.`
      );
    }

    notes.push(
      `Изчислен товар по фази: L1 ${phaseLoad.L1.toFixed(0)} A, L2 ${phaseLoad.L2.toFixed(
        0
      )} A, L3 ${phaseLoad.L3.toFixed(0)} A.`
    );
    notes.push(
      `Главен прекъсвач ${mainCurrent} A при коефициент на едновременност ${options.diversityFactor}.`
    );
    notes.push(
      `${planned.length} кръга, ${offset} заети модула + ${options.spareModules} резервни.`
    );

    for (const load of loads) {
      const profile = LOAD_PROFILES[load.kind];
      const threePhase = load.threePhase && options.supplyPhases === 3;
      const currentA = this.currentFor(load.powerKw / Math.max(1, load.quantity), threePhase);
      const mm2 = this.wiring.crossSectionFor(this.pickRating(currentA));
      const drop = this.wiring.voltageDrop(currentA, load.lengthM, mm2, threePhase);
      if (drop > 4) {
        notes.push(
          `„${load.name}“: при ${load.lengthM} m и ${mm2} mm² падът е ${drop}% — увеличете сечението.`
        );
      }
      void profile;
    }

    return {
      devices,
      enclosure,
      totalModules,
      mainBreakerCurrentA: mainCurrent,
      phaseLoadA: phaseLoad,
      notes,
      unresolved,
    };
  }

  /** Current drawn by a load, assuming cos φ = 0.95. */
  private currentFor(powerKw: number, threePhase: boolean): number {
    const powerFactor = 0.95;
    return threePhase
      ? (powerKw * 1000) / (Math.sqrt(3) * 400 * powerFactor)
      : (powerKw * 1000) / (230 * powerFactor);
  }

  private pickRating(currentA: number): number {
    return MCB_RATINGS.find((r) => r >= currentA * 1.1) ?? MCB_RATINGS[MCB_RATINGS.length - 1];
  }

  /** An RCD must be rated at or above the breaker it protects. */
  private pickRcdRating(currentA: number): number {
    return [25, 40, 63, 80].find((r) => r >= currentA) ?? 63;
  }

  private leastLoadedPhase(
    load: Record<'L1' | 'L2' | 'L3', number>,
    supplyPhases: 1 | 3
  ): 'L1' | 'L2' | 'L3' {
    if (supplyPhases === 1) return 'L1';
    return (['L1', 'L2', 'L3'] as const).reduce((best, p) => (load[p] < load[best] ? p : best), 'L1');
  }

  private pickEnclosure(
    modules: number,
    catalog: EtiProduct[]
  ): ConfiguratorResult['enclosure'] {
    const candidates = catalog
      .filter((p) => (p.category ?? '') === 'Enclosure')
      .map((p) => {
        const attrs = (p.raw_attributes ?? {}) as { modules_per_row?: number; rows?: number };
        const modulesPerRow = attrs.modules_per_row ?? p.width_modules ?? 12;
        const rows = attrs.rows ?? 1;
        return { product: p, modulesPerRow, rows, capacity: modulesPerRow * rows };
      })
      .filter((c) => c.capacity >= modules)
      .sort((a, b) => a.capacity - b.capacity);

    const best = candidates[0];
    if (!best) return null;
    return {
      catalogNumber: best.product.catalog_number,
      name: best.product.name,
      rows: best.rows,
      modulesPerRow: best.modulesPerRow,
    };
  }

  /**
   * Lookup helpers over the catalog, matching on the real ETI attributes.
   *
   * Each lookup tries the exact match first and then relaxes one attribute at a
   * time. Ratings only ever step **up**: substituting a larger breaker keeps the
   * installation safe, whereas a smaller one would nuisance-trip. Poles may only
   * increase too, since a 4-pole device can serve a single-phase circuit but not
   * the other way round. Sensitivity may only be relaxed towards a *more*
   * sensitive device: fitting 30 mA where 300 mA was asked for keeps the shock
   * protection valid, while the reverse would silently weaken it.
   */
  private buildIndex(catalog: EtiProduct[]) {
    const num = (v: string | number | null | undefined) =>
      v === null || v === undefined ? null : Number(v);

    const byCategory = (category: string) =>
      catalog.filter((p) => (p.category ?? '').toUpperCase() === category);

    const mcbs = byCategory('MCB');
    const rcds = byCategory('RCD');
    const rcbos = byCategory('RCBO');
    const spds = byCategory('SPD');
    const switches = byCategory('SWITCH');

    /** Smallest product at or above `rating` among those passing `predicate`. */
    const smallestAtLeast = (
      pool: EtiProduct[],
      rating: number,
      predicate: (p: EtiProduct) => boolean
    ): EtiProduct | undefined =>
      pool
        .filter((p) => predicate(p) && (num(p.rated_current_a) ?? 0) >= rating)
        .sort((a, b) => (num(a.rated_current_a) ?? 0) - (num(b.rated_current_a) ?? 0))[0];

    const curveOf = (p: EtiProduct) => (p.trip_curve ?? '').toUpperCase();
    const sensitivityMatches = (p: EtiProduct, sensitivity: number) =>
      Math.abs((num(p.residual_current_a) ?? 0) - sensitivity) < 0.0001;

    return {
      mcb: (rating: number, poles: number, curve: string) =>
        smallestAtLeast(mcbs, rating, (p) => p.poles === poles && curveOf(p) === curve) ??
        smallestAtLeast(mcbs, rating, (p) => p.poles === poles) ??
        smallestAtLeast(mcbs, rating, (p) => (p.poles ?? 1) >= poles),

      rcbo: (rating: number, curve: string) =>
        smallestAtLeast(rcbos, rating, (p) => curveOf(p) === curve) ??
        smallestAtLeast(rcbos, rating, () => true),

      rcd: (rating: number, poles: number, sensitivity: number) =>
        smallestAtLeast(
          rcds,
          rating,
          (p) => p.poles === poles && sensitivityMatches(p, sensitivity) && (p.rcd_type ?? '') === 'A'
        ) ??
        smallestAtLeast(
          rcds,
          rating,
          (p) => p.poles === poles && sensitivityMatches(p, sensitivity)
        ) ??
        smallestAtLeast(
          rcds,
          rating,
          (p) => (p.poles ?? 2) >= poles && sensitivityMatches(p, sensitivity)
        ) ??
        // Fall back to the most sensitive device that is still no coarser than
        // requested, preferring the closest match to limit nuisance tripping.
        rcds
          .filter(
            (p) =>
              (p.poles ?? 2) >= poles &&
              (num(p.rated_current_a) ?? 0) >= rating &&
              (num(p.residual_current_a) ?? 1) <= sensitivity
          )
          .sort(
            (a, b) =>
              (num(b.residual_current_a) ?? 0) - (num(a.residual_current_a) ?? 0) ||
              (num(a.rated_current_a) ?? 0) - (num(b.rated_current_a) ?? 0)
          )[0],

      mainSwitch: (rating: number, poles: number) =>
        smallestAtLeast(switches, rating, (p) => p.poles === poles) ??
        smallestAtLeast(switches, rating, (p) => (p.poles ?? 2) >= poles) ??
        switches.find((p) => p.poles === poles),

      spd1: spds.find((p) => p.width_modules === 1),
      spd4: spds.find((p) => p.poles === 4) ?? spds.find((p) => p.width_modules === 4),
    };
  }

  /** Conductor set a configured circuit will use, for display purposes. */
  conductorsFor(threePhase: boolean): Conductor[] {
    return threePhase ? ['L1', 'L2', 'L3', 'N'] : ['L1', 'N'];
  }
}
