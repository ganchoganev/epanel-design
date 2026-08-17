import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  ConfiguratorOptions,
  ConfiguratorResult,
  ConfiguratorService,
  LoadKind,
  LoadSpec,
} from '../services/configurator.service';
import { EtiProduct } from '../models/catalog.models';

const LOAD_KINDS: Array<{ value: LoadKind; label: string; defaultKw: number }> = [
  { value: 'lighting', label: 'Осветление', defaultKw: 1.0 },
  { value: 'sockets', label: 'Контакти', defaultKw: 2.0 },
  { value: 'kitchen', label: 'Кухня', defaultKw: 3.5 },
  { value: 'boiler', label: 'Бойлер', defaultKw: 3.0 },
  { value: 'ac', label: 'Климатик', defaultKw: 2.5 },
  { value: 'oven', label: 'Фурна / плот', defaultKw: 7.0 },
  { value: 'motor', label: 'Двигател', defaultKw: 4.0 },
  { value: 'ev_charger', label: 'Зарядна станция', defaultKw: 11.0 },
  { value: 'other', label: 'Друго', defaultKw: 1.5 },
];

let loadCounter = 0;

/**
 * Wizard that turns a list of loads into a ready panel. The designer enters
 * what has to be supplied, the configurator picks real ETI apparatus and the
 * result can be pushed straight into the editor.
 */
@Component({
  selector: 'app-configurator',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './configurator.component.html',
  styleUrl: './configurator.component.scss',
})
export class ConfiguratorComponent {
  private service = inject(ConfiguratorService);

  readonly catalog = input<EtiProduct[]>([]);
  readonly applyResult = output<ConfiguratorResult>();

  readonly kinds = LOAD_KINDS;

  readonly loads = signal<LoadSpec[]>([
    this.makeLoad('lighting', 'Осветление партер', 1.2, 2),
    this.makeLoad('sockets', 'Контакти партер', 2.0, 3),
    this.makeLoad('kitchen', 'Кухня', 3.5, 1),
    this.makeLoad('boiler', 'Бойлер 3 kW', 3.0, 1),
  ]);

  readonly options = signal<ConfiguratorOptions>({
    supplyPhases: 3,
    diversityFactor: 0.7,
    perCircuitRcbo: false,
    includeSpd: true,
    includeMainSwitch: true,
    spareModules: 4,
  });

  readonly result = signal<ConfiguratorResult | null>(null);

  readonly totalPowerKw = computed(() =>
    Math.round(this.loads().reduce((sum, l) => sum + l.powerKw, 0) * 10) / 10
  );

  readonly circuitCount = computed(() =>
    this.loads().reduce((sum, l) => sum + Math.max(1, l.quantity), 0)
  );

  private makeLoad(
    kind: LoadKind,
    name: string,
    powerKw: number,
    quantity: number
  ): LoadSpec {
    loadCounter += 1;
    return {
      id: `l${loadCounter}`,
      name,
      kind,
      powerKw,
      quantity,
      threePhase: false,
      lengthM: 20,
    };
  }

  addLoad(): void {
    this.loads.update((loads) => [...loads, this.makeLoad('other', 'Нов товар', 1.5, 1)]);
  }

  removeLoad(id: string): void {
    this.loads.update((loads) => loads.filter((l) => l.id !== id));
  }

  updateLoad(id: string, patch: Partial<LoadSpec>): void {
    this.loads.update((loads) =>
      loads.map((l) => (l.id === id ? { ...l, ...patch } : l))
    );
  }

  onKindChange(id: string, kind: LoadKind): void {
    const preset = LOAD_KINDS.find((k) => k.value === kind);
    this.updateLoad(id, {
      kind,
      powerKw: preset?.defaultKw ?? 1.5,
      threePhase: kind === 'motor' || kind === 'ev_charger',
    });
  }

  updateOption<K extends keyof ConfiguratorOptions>(
    key: K,
    value: ConfiguratorOptions[K]
  ): void {
    this.options.update((o) => ({ ...o, [key]: value }));
  }

  calculate(): void {
    const result = this.service.configure(this.loads(), this.options(), this.catalog());
    this.result.set(result);
    if (result.enclosure && result.devices.length) {
      this.applyResult.emit(result);
    }
  }

  apply(): void {
    const result = this.result();
    if (result) this.applyResult.emit(result);
  }

  numberOf(value: string): number {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  }
}
