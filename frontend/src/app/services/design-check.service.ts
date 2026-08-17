import { Injectable } from '@angular/core';
import {
  Conductor,
  DesignData,
  DesignWarning,
  formatMm2,
  PlacedComponent,
} from '../models/project.models';
import { crossSectionForCurrent } from './wiring.service';

/**
 * Maximum acceptable imbalance between the loaded phases before the panel is
 * flagged. Above this the neutral carries significant current and the supply
 * cable is sized for the worst phase rather than the average.
 */
const PHASE_IMBALANCE_LIMIT = 0.25;

/** Spare modules a panel should keep for later extensions (IEC 61439 practice). */
const RESERVE_FRACTION = 0.2;

/** Voltage drop limit for final circuits per IEC 60364 / BDS HD 60364. */
const VOLTAGE_DROP_LIMIT_PERCENT = 4;

/**
 * Runs the engineering rules over a finished layout and returns everything the
 * designer should look at before releasing the drawing. Rules are deliberately
 * advisory: they never block editing, they just surface what is wrong.
 */
@Injectable({ providedIn: 'root' })
export class DesignCheckService {
  run(design: DesignData): DesignWarning[] {
    return [
      ...this.checkFill(design),
      ...this.checkThermal(design),
      ...this.checkPhaseBalance(design),
      ...this.checkProtection(design),
      ...this.checkSelectivity(design),
      ...this.checkWiring(design),
    ];
  }

  private checkFill(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];
    const capacity = design.enclosure.rows * design.enclosure.modulesPerRow;
    if (!capacity) return warnings;

    const used = design.components.reduce((sum, c) => sum + c.widthModules, 0);
    const fill = used / capacity;

    if (used > capacity) {
      warnings.push({
        id: 'fill-overflow',
        severity: 'error',
        category: 'fill',
        message: `Апаратите заемат ${used} модула, а таблото има ${capacity}. Изберете по-голямо ETIBOX табло.`,
      });
    } else if (fill > 1 - RESERVE_FRACTION) {
      warnings.push({
        id: 'fill-no-reserve',
        severity: 'warning',
        category: 'fill',
        message: `Запълване ${Math.round(fill * 100)}%. Добра практика е да остане поне ${Math.round(
          RESERVE_FRACTION * 100
        )}% резерв за бъдещи кръгове.`,
      });
    }

    // A device that hangs over the end of its row cannot physically be mounted.
    for (const comp of design.components) {
      if (comp.startModule + comp.widthModules > design.enclosure.modulesPerRow) {
        warnings.push({
          id: `fill-overhang-${comp.uid}`,
          severity: 'error',
          category: 'fill',
          message: `${comp.label} излиза извън редицата (${comp.startModule + comp.widthModules} > ${
            design.enclosure.modulesPerRow
          } модула).`,
          componentUids: [comp.uid],
        });
      }
    }

    return warnings;
  }

  private checkThermal(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];
    const total = design.components.reduce((sum, c) => sum + (c.heatDissipationW ?? 0), 0);
    const limit = design.enclosure.thermalLimitW ?? 45;
    const ambient = design.enclosure.ambientTempC ?? 30;

    // Above 30 °C the enclosure sheds less heat, so the usable limit shrinks.
    const derated = limit * (1 - Math.max(0, ambient - 30) * 0.02);

    if (total > derated) {
      warnings.push({
        id: 'thermal-over',
        severity: 'error',
        category: 'thermal',
        message: `Отделяна мощност ${total.toFixed(1)} W надвишава допустимата за таблото ${derated.toFixed(
          1
        )} W при ${ambient} °C. Нужна е вентилация или по-голямо табло.`,
      });
    } else if (total > derated * 0.8) {
      warnings.push({
        id: 'thermal-warn',
        severity: 'warning',
        category: 'thermal',
        message: `Отделяна мощност ${total.toFixed(1)} W е ${Math.round(
          (total / derated) * 100
        )}% от допустимата. Проверете температурния режим.`,
      });
    }

    return warnings;
  }

  private checkPhaseBalance(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];
    if ((design.enclosure.phases ?? 3) !== 3) return warnings;

    const load: Record<'L1' | 'L2' | 'L3', number> = { L1: 0, L2: 0, L3: 0 };
    const circuits = design.wiring.circuits;
    const byUid = new Map(design.components.map((c) => [c.uid, c]));

    // The largest single-phase circuit sets the floor on how even the phases can
    // possibly be, because one circuit cannot be split across two phases.
    let largestSinglePhase = 0;

    for (const circuit of circuits) {
      const comp = byUid.get(circuit.protectiveDeviceUid);
      const phases = circuit.conductors.filter((c): c is 'L1' | 'L2' | 'L3' =>
        c === 'L1' || c === 'L2' || c === 'L3'
      );
      if (!phases.length) continue;
      const current = comp?.ratedCurrentA ?? 0;
      if (phases.length === 1) {
        largestSinglePhase = Math.max(largestSinglePhase, current);
      }
      const share = current / phases.length;
      for (const phase of phases) load[phase] += share;
    }

    const values = [load.L1, load.L2, load.L3];
    const max = Math.max(...values);
    const min = Math.min(...values);
    if (max <= 0) return warnings;

    // Nothing to report when the spread is no larger than one circuit: no
    // reshuffling of circuits could even out the phases any further.
    if (max - min <= largestSinglePhase) return warnings;

    const imbalance = (max - min) / max;
    if (imbalance > PHASE_IMBALANCE_LIMIT) {
      warnings.push({
        id: 'phase-imbalance',
        severity: 'warning',
        category: 'phase',
        message: `Несиметрично натоварване по фази: L1 ${load.L1.toFixed(0)} A, L2 ${load.L2.toFixed(
          0
        )} A, L3 ${load.L3.toFixed(0)} A (разлика ${Math.round(imbalance * 100)}%).`,
      });
    }

    return warnings;
  }

  private checkProtection(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];
    const hasRcdProtection = design.components.some((c) => this.isRcd(c) || this.isRcbo(c));
    const socketCircuits = design.components.filter(
      (c) => this.isMcb(c) && (c.ratedCurrentA ?? 0) <= 20 && (c.poles ?? 1) === 1
    );

    if (socketCircuits.length && !hasRcdProtection) {
      warnings.push({
        id: 'protection-no-rcd',
        severity: 'error',
        category: 'protection',
        message:
          'Няма дефектнотокова защита. Крайните кръгове за контакти изискват RCD 30 mA (Наредба 3 / IEC 60364-4-41).',
        componentUids: socketCircuits.map((c) => c.uid),
      });
    }

    // A 30 mA RCD may protect only a limited number of circuits before nuisance
    // tripping from accumulated leakage current becomes likely.
    for (const rcd of design.components.filter((c) => this.isRcd(c))) {
      const downstream = design.wiring.wires.filter(
        (w) => w.from.componentUid === rcd.uid && w.conductor !== 'PE'
      );
      const fedDevices = new Set(downstream.map((w) => w.to.componentUid));
      const viaBusbar = design.wiring.busbars
        .filter((b) => b.sourceUid === rcd.uid)
        .flatMap((b) => b.targetUids);
      for (const uid of viaBusbar) fedDevices.add(uid);

      if ((rcd.residualCurrentA ?? 0) <= 0.03 && fedDevices.size > 6) {
        warnings.push({
          id: `protection-rcd-load-${rcd.uid}`,
          severity: 'warning',
          category: 'protection',
          message: `${rcd.label} (30 mA) защитава ${fedDevices.size} кръга. Над 6 кръга сумарният утечен ток често причинява неоправдани изключвания — разделете на две групи.`,
          componentUids: [rcd.uid],
        });
      }
    }

    const spd = design.components.find((c) => this.isSpd(c));
    if (!spd) {
      warnings.push({
        id: 'protection-no-spd',
        severity: 'info',
        category: 'protection',
        message: 'Няма катоден отводител (ETITEC). За сгради с външна мълниезащита е задължителен SPD T1+T2.',
      });
    }

    return warnings;
  }

  private checkSelectivity(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];
    const byUid = new Map(design.components.map((c) => [c.uid, c]));

    const feeds = new Map<string, string[]>();
    for (const wire of design.wiring.wires) {
      if (wire.conductor === 'PE') continue;
      const list = feeds.get(wire.from.componentUid) ?? [];
      list.push(wire.to.componentUid);
      feeds.set(wire.from.componentUid, list);
    }
    for (const busbar of design.wiring.busbars) {
      const list = feeds.get(busbar.sourceUid) ?? [];
      list.push(...busbar.targetUids);
      feeds.set(busbar.sourceUid, list);
    }

    for (const [sourceUid, targets] of feeds) {
      const source = byUid.get(sourceUid);
      if (!source?.ratedCurrentA) continue;

      for (const targetUid of new Set(targets)) {
        const target = byUid.get(targetUid);
        if (!target?.ratedCurrentA) continue;

        if (target.ratedCurrentA > source.ratedCurrentA) {
          warnings.push({
            id: `sel-rating-${sourceUid}-${targetUid}`,
            severity: 'error',
            category: 'selectivity',
            message: `${target.label} (${target.ratedCurrentA} A) е с по-голям номинал от захранващия ${source.label} (${source.ratedCurrentA} A).`,
            componentUids: [sourceUid, targetUid],
          });
        } else if (
          this.isMcb(target) &&
          this.isMcb(source) &&
          target.ratedCurrentA / source.ratedCurrentA > 0.66
        ) {
          // MCB-to-MCB selectivity needs roughly a 1:1.6 rating step.
          warnings.push({
            id: `sel-step-${sourceUid}-${targetUid}`,
            severity: 'warning',
            category: 'selectivity',
            message: `Слаба селективност: ${source.label} (${source.ratedCurrentA} A) → ${target.label} (${target.ratedCurrentA} A). Нужно е съотношение поне 1:1,6.`,
            componentUids: [sourceUid, targetUid],
          });
        }
      }
    }

    return warnings;
  }

  private checkWiring(design: DesignData): DesignWarning[] {
    const warnings: DesignWarning[] = [];

    if (!design.components.length) return warnings;

    if (!design.wiring.generatedAt) {
      warnings.push({
        id: 'wiring-not-generated',
        severity: 'info',
        category: 'wiring',
        message: 'Окабеляването още не е генерирано. Натиснете „Генерирай окабеляване“.',
      });
      return warnings;
    }

    const wired = new Set<string>();
    for (const wire of design.wiring.wires) {
      wired.add(wire.to.componentUid);
      wired.add(wire.from.componentUid);
    }
    for (const busbar of design.wiring.busbars) {
      wired.add(busbar.sourceUid);
      for (const uid of busbar.targetUids) wired.add(uid);
    }

    const orphans = design.components.filter(
      (c) => !wired.has(c.uid) && c.uid !== design.wiring.incomingUid
    );
    if (orphans.length) {
      warnings.push({
        id: 'wiring-orphans',
        severity: 'warning',
        category: 'wiring',
        message: `${orphans.length} апарата не са свързани: ${orphans
          .map((c) => c.label)
          .join(', ')}.`,
        componentUids: orphans.map((c) => c.uid),
      });
    }

    for (const busbar of design.wiring.busbars) {
      if (busbar.spanModules > 12) {
        warnings.push({
          id: `wiring-busbar-span-${busbar.id}`,
          severity: 'warning',
          category: 'wiring',
          message: `Гребенна шина на ред ${busbar.row + 1} обхваща ${busbar.spanModules} модула. Стандартната IZS е 12 модула — нужни са ${Math.ceil(
            busbar.spanModules / 12
          )} бр.`,
        });
      }

      const load = busbar.targetUids
        .map((uid) => design.components.find((c) => c.uid === uid)?.ratedCurrentA ?? 0)
        .reduce((a, b) => a + b, 0);
      if (load > busbar.ratedCurrentA * 1.6) {
        warnings.push({
          id: `wiring-busbar-load-${busbar.id}`,
          severity: 'warning',
          category: 'wiring',
          message: `Сумата от номиналите върху гребенната шина (${load} A) е висока за ${busbar.ratedCurrentA} A шина. Проверете коефициента на едновременност.`,
        });
      }
    }

    for (const bar of design.wiring.bars) {
      if (bar.usedWays > bar.ways) {
        warnings.push({
          id: `wiring-bar-${bar.id}`,
          severity: 'error',
          category: 'wiring',
          message: `${bar.name}: нужни са ${bar.usedWays} клеми, налични ${bar.ways}. Добавете още една шина.`,
        });
      }
    }

    const byUid = new Map(design.components.map((c) => [c.uid, c]));

    for (const circuit of design.wiring.circuits) {
      if ((circuit.voltageDropPercent ?? 0) > VOLTAGE_DROP_LIMIT_PERCENT) {
        warnings.push({
          id: `wiring-drop-${circuit.id}`,
          severity: 'warning',
          category: 'wiring',
          message: `Кръг ${circuit.number}: пад на напрежението ${circuit.voltageDropPercent}% при ${circuit.lengthM} m надвишава ${VOLTAGE_DROP_LIMIT_PERCENT}%. Увеличете сечението.`,
          componentUids: [circuit.protectiveDeviceUid],
        });
      }
      const device = byUid.get(circuit.protectiveDeviceUid);
      const rating = device?.ratedCurrentA;
      if (rating) {
        const minMm2 = crossSectionForCurrent(rating);
        if (circuit.cableCrossSectionMm2 < minMm2) {
          warnings.push({
            id: `wiring-section-${circuit.id}`,
            severity: 'warning',
            category: 'wiring',
            message: `Кръг ${circuit.number}: сечение ${formatMm2(circuit.cableCrossSectionMm2)} mm² е по-малко от препоръчаното ${formatMm2(minMm2)} mm² за ${rating} A.`,
            componentUids: [circuit.protectiveDeviceUid],
          });
        }
      }
    }

    return warnings;
  }

  private category(comp: PlacedComponent): string {
    return (comp.category ?? '').toUpperCase();
  }

  private isMcb(comp: PlacedComponent): boolean {
    return this.category(comp).includes('MCB');
  }

  private isRcd(comp: PlacedComponent): boolean {
    return this.category(comp) === 'RCD';
  }

  private isRcbo(comp: PlacedComponent): boolean {
    return this.category(comp).includes('RCBO');
  }

  private isSpd(comp: PlacedComponent): boolean {
    return this.category(comp).includes('SPD');
  }

  /** Conductors carrying current, excluding the protective earth. */
  liveConductors(conductors: Conductor[]): Conductor[] {
    return conductors.filter((c) => c !== 'PE');
  }
}
