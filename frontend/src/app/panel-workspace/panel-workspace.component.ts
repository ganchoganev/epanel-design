import { Component, computed, DestroyRef, effect, inject, signal, viewChild } from '@angular/core';
import { catchError, forkJoin, map, of } from 'rxjs';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PanelEditorComponent } from '../editor/panel-editor.component';
import { PriceImportComponent } from '../price-import/price-import.component';
import { ConfiguratorComponent } from '../configurator/configurator.component';
import { SingleLineDiagramComponent } from '../schematic/single-line-diagram.component';
import { StepTrailComponent } from '../steps/step-trail.component';
import { ApiService } from '../services/api.service';
import { DesignStore } from '../services/design-store.service';
import { ComponentGroup, EtiProduct } from '../models/catalog.models';
import { Bom, EnclosureConfig, PlacedComponent, ProjectSummary, EditorLayerId, EditorLayers, DEFAULT_EDITOR_LAYERS, EDITOR_LAYER_META, CABLE_KINDS, CABLE_SECTIONS_MM2, Circuit, parseCableKind, Wire } from '../models/project.models';
import { OfferDraftStore } from '../offers/offer-draft.store';
import { deviceModules, loadEnclosureChoices, suggestEnclosure } from '../offers/board-fit';
import { BuiltBoard, designFromOfferBoard } from '../offers/offer-board-design';
import { ConfiguratorResult } from '../services/configurator.service';
import { AuthService } from '../services/auth.service';

type Panel = 'board' | 'catalog' | 'groups' | 'configurator' | 'circuits' | 'projects' | 'prices';
type Workspace = 'layout' | 'schematic';
type MobilePane = 'canvas' | 'library' | 'inspect';

/**
 * Real ETIBOX distribution boards. Thermal limits are the power the enclosure
 * can dissipate through its surface at 30 °C ambient, scaled with its volume.
 */
const ENCLOSURE_PRESETS: EnclosureConfig[] = [
  { catalogNumber: '001101010', name: 'ETIBOX ECT 12 (1x12)', rows: 1, modulesPerRow: 12, supplySystem: 'TN-C-S', phases: 3, ipRating: 'IP40', thermalLimitW: 25, ambientTempC: 30 },
  { catalogNumber: '001101011', name: 'ETIBOX ECT 24 (2x12)', rows: 2, modulesPerRow: 12, supplySystem: 'TN-C-S', phases: 3, ipRating: 'IP40', thermalLimitW: 45, ambientTempC: 30 },
  { catalogNumber: '001101012', name: 'ETIBOX ECT 36 (3x12)', rows: 3, modulesPerRow: 12, supplySystem: 'TN-C-S', phases: 3, ipRating: 'IP40', thermalLimitW: 65, ambientTempC: 30 },
  { catalogNumber: '001101013', name: 'ETIBOX ECT 48 (4x12)', rows: 4, modulesPerRow: 12, supplySystem: 'TN-C-S', phases: 3, ipRating: 'IP40', thermalLimitW: 85, ambientTempC: 30 },
  { catalogNumber: '001101020', name: 'ETIBOX ECT 72 (4x18)', rows: 4, modulesPerRow: 18, supplySystem: 'TN-C-S', phases: 3, ipRating: 'IP40', thermalLimitW: 110, ambientTempC: 30 },
];

@Component({
  selector: 'app-panel-workspace',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    PanelEditorComponent,
    PriceImportComponent,
    ConfiguratorComponent,
    SingleLineDiagramComponent,
    StepTrailComponent,
  ],
  templateUrl: './panel-workspace.component.html',
  styleUrl: './panel-workspace.component.scss',
})
export class PanelWorkspaceComponent {
  private api = inject(ApiService);
  private offerStore = inject(OfferDraftStore);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);
  store = inject(DesignStore);

  readonly enclosurePresets = ENCLOSURE_PRESETS;
  /** Cable and wiring screens stay in the code and can be turned back on later. */
  readonly showWiring: boolean = false;
  activePanel = signal<Panel>('board');
  workspace = signal<Workspace>('layout');
  /** Phone/tablet: canvas first, library and inspector as drawers. */
  isNarrow = signal(false);
  mobilePane = signal<MobilePane>('canvas');
  overflowOpen = signal(false);

  readonly layerMeta = EDITOR_LAYER_META;
  layers = signal<EditorLayers>({
    ...DEFAULT_EDITOR_LAYERS,
    cables: false,
    comb: false,
    bars: false,
    labels: false,
  });

  readonly warnings = computed(() => {
    const all = this.store.warnings();
    return this.showWiring ? all : all.filter((warning) => warning.category !== 'wiring');
  });
  readonly placedGroups = computed(() => {
    const groups = new Map<string, { code: string; name: string; qty: number; modules: number; uid: string }>();
    for (const component of this.store.components()) {
      const current = groups.get(component.catalogNumber);
      if (current) {
        current.qty += 1;
      } else {
        groups.set(component.catalogNumber, {
          code: component.catalogNumber,
          name: component.name,
          qty: 1,
          modules: component.widthModules,
          uid: component.uid,
        });
      }
    }
    return [...groups.values()];
  });
  readonly errorCount = computed(() => this.warnings().filter((w) => w.severity === 'error').length);
  readonly warningCount = computed(
    () => this.warnings().filter((w) => w.severity === 'warning').length
  );

  products = signal<EtiProduct[]>([]);
  private productMap = computed(() => new Map(this.products().map((p) => [p.catalog_number, p])));
  series = signal<string[]>([]);
  groups = signal<ComponentGroup[]>([]);
  readonly factoryGroups = computed(() => this.groups().filter((g) => g.is_system));
  readonly customGroups = computed(() => this.groups().filter((g) => !g.is_system));
  newGroupName = signal('');
  newGroupDescription = signal('');
  projects = signal<ProjectSummary[]>([]);

  search = signal('');
  selectedSeries = signal('');
  selected = signal<PlacedComponent | null>(null);
  selectedWireId = signal<string | null>(null);
  connectMode = signal(false);
  bom = signal<Bom | null>(null);
  readonly cableKinds = CABLE_KINDS;
  readonly cableSections = CABLE_SECTIONS_MM2;
  readonly selectedLive = computed(() => {
    const uid = this.selected()?.uid;
    if (!uid) return null;
    return this.store.components().find((c) => c.uid === uid) ?? this.selected();
  });
  readonly selectedCircuit = computed(() => {
    const sel = this.selectedLive();
    if (!sel) return null;
    return this.store.circuits().find((c) => c.protectiveDeviceUid === sel.uid) ?? null;
  });
  readonly selectedWire = computed((): Wire | null => {
    const id = this.selectedWireId();
    if (!id) return null;
    return this.store.wiring().wires.find((w) => w.id === id) ?? null;
  });
  readonly feedCandidates = computed(() => {
    const sel = this.selectedLive();
    if (!sel) return [];
    return this.store.components().filter((c) => c.uid !== sel.uid);
  });

  readonly createdBoards = signal<BuiltBoard[]>([]);
  readonly activeCreated = signal<string | null>(null);
  readonly devicePhotos = signal<Record<string, string>>({});
  private editor = viewChild(PanelEditorComponent);
  private photoLoading = new Set<string>();

  projectName = signal('Ново табло');
  clientName = signal('');
  currentProjectId = signal<number | null>(null);
  statusMessage = signal<string>('');

  constructor() {
    this.bindViewport();
    this.store.reset();
    this.auth.hydrate().subscribe((ok) => {
      if (ok) this.bootstrapData();
    });
    effect(() => {
      const codes = new Set(this.store.components().map((item) => item.catalogNumber));
      const enclosure = this.store.enclosure().catalogNumber;
      if (enclosure) codes.add(enclosure);
      for (const code of codes) this.loadDevicePhoto(code);
    });
    this.destroyRef.onDestroy(() => {
      for (const url of Object.values(this.devicePhotos())) URL.revokeObjectURL(url);
    });
  }

  logout(): void {
    this.auth.logout();
    this.store.reset();
    this.selected.set(null);
    this.bom.set(null);
    this.currentProjectId.set(null);
  }

  private bootstrapData(): void {
    this.loadCatalog();
    this.loadGroups();
    this.loadProjects();
    this.loadCreatedBoards();
  }

  private bindViewport(): void {
    if (typeof window === 'undefined') return;
    const mq = window.matchMedia('(max-width: 960px)');
    const apply = () => this.isNarrow.set(mq.matches);
    apply();
    mq.addEventListener('change', apply);
    this.destroyRef.onDestroy(() => mq.removeEventListener('change', apply));
  }

  openLibrary(): void {
    this.mobilePane.set('library');
    this.overflowOpen.set(false);
  }

  openInspect(): void {
    this.mobilePane.set('inspect');
    this.overflowOpen.set(false);
  }

  showCanvas(): void {
    this.mobilePane.set('canvas');
    this.overflowOpen.set(false);
  }

  closeOverlays(): void {
    this.overflowOpen.set(false);
    if (this.isNarrow()) this.mobilePane.set('canvas');
  }

  toggleOverflow(): void {
    this.overflowOpen.update((open) => !open);
  }

  private loadCatalog(): void {
    const params: Record<string, string | number> = { per_page: 200 };
    if (this.search()) params['search'] = this.search();
    if (this.selectedSeries()) params['series'] = this.selectedSeries();
    this.api.getProducts(params).subscribe((res) => this.products.set(res.data));
    this.api.getSeries().subscribe((res) => this.series.set(res.series));
  }

  private loadGroups(): void {
    this.api.getGroups().subscribe((g) => this.groups.set(g));
  }

  private loadProjects(): void {
    this.api.listProjects().subscribe((p) => this.projects.set(p));
  }

  onSearchChange(): void {
    this.loadCatalog();
  }

  addProduct(product: EtiProduct): void {
    const placed = this.store.addProduct(product);
    if (!placed) {
      this.flash('Няма свободно място в таблото. Добавете ред или по-голям корпус.');
      return;
    }
    this.refreshBom();
    if (this.isNarrow()) this.flash(`Добавен ${product.name}`);
  }

  addGroup(group: ComponentGroup): void {
    const ok = this.store.addGroup(group, this.productMap());
    if (!ok) {
      this.flash('Няма достатъчно място за групата.');
      return;
    }
    this.flash(`Добавена група „${group.name}“. Мести се като едно цяло.`);
    this.refreshBom();
  }

  saveCustomGroup(): void {
    const components = [...this.store.components()].sort(
      (a, b) => a.row - b.row || a.startModule - b.startModule
    );
    if (!components.length) {
      this.flash('Поставете апарати в таблото, после ги запазете като група.');
      return;
    }
    const name = this.newGroupName().trim();
    if (!name) {
      this.flash('Въведете име на групата.');
      return;
    }
    const origin = components[0].startModule;
    const items = components.map((c) => ({
      catalog_number: c.catalogNumber,
      label: c.label,
      offset_module: Math.max(0, c.startModule - origin),
    }));
    this.api
      .createGroup({
        name,
        description: this.newGroupDescription().trim() || `Собствена група · ${items.length} апарата`,
        items,
        connections: [],
      })
      .subscribe({
        next: (group) => {
          this.groups.update((list) => [...list, group]);
          this.newGroupName.set('');
          this.newGroupDescription.set('');
          this.flash(`Групата „${group.name}“ е записана. Кликни върху нея, за да я добавиш отново.`);
        },
        error: () => this.flash('Групата не можа да се запише.'),
      });
  }

  deleteCustomGroup(group: ComponentGroup, event: Event): void {
    event.stopPropagation();
    if (group.is_system) return;
    this.api.deleteGroup(group.id).subscribe({
      next: () => {
        this.groups.update((list) => list.filter((g) => g.id !== group.id));
        this.flash(`Групата „${group.name}“ е изтрита.`);
      },
      error: () => this.flash('Системните групи не могат да се изтриват.'),
    });
  }

  onEnclosureChange(preset: EnclosureConfig): void {
    this.activeCreated.set(null);
    this.store.setEnclosure({ ...preset });
    this.refreshBom();
  }

  showCreated(name: string): void {
    const board = this.createdBoards().find((item) => item.name === name);
    if (!board) return;
    this.store.load(board.design);
    this.activeCreated.set(name);
    this.projectName.set(name);
    this.currentProjectId.set(null);
    this.selected.set(null);
    this.selectedWireId.set(null);
    this.workspace.set('layout');
    this.refreshBom();
    const count = board.design.components.length;
    const fit = board.leftover > 0 ? ` ${board.leftover} модула не се събраха.` : '';
    this.flash(`Начертано е ${name}: ${count} апарата в ${board.design.enclosure.name}.${fit}`);
  }

  selectPlaced(uid: string): void {
    const component = this.store.components().find((item) => item.uid === uid) ?? null;
    this.onSelect(component);
  }

  onSelect(component: PlacedComponent | null): void {
    this.selected.set(component);
    if (component) this.selectedWireId.set(null);
    if (component && this.isNarrow()) this.mobilePane.set('inspect');
  }

  onSelectWire(wireId: string | null): void {
    this.selectedWireId.set(wireId);
    if (wireId) this.selected.set(null);
    if (wireId && this.isNarrow()) this.mobilePane.set('inspect');
  }

  onConnectionChange(message: string): void {
    this.flash(message);
    this.refreshBom();
  }

  toggleConnectMode(): void {
    this.connectMode.update((on) => !on);
    if (this.connectMode()) {
      this.setLayerPreset('wiring');
      this.flash('Режим връзка: кликни източник, после приемник.');
    }
  }

  setSelectedFeed(value: string): void {
    const sel = this.selectedLive();
    if (!sel) return;
    const result = this.store.setFeedFrom(sel.uid, value || null);
    if (!result.ok) {
      this.flash(result.message ?? 'Връзката не можа да се запише.');
      return;
    }
    this.refreshBom();
  }

  deleteSelectedWire(): void {
    const id = this.selectedWireId();
    if (!id) return;
    this.store.removeWire(id);
    this.selectedWireId.set(null);
    this.refreshBom();
  }

  restoreBarDrop(conductor: 'N' | 'PE'): void {
    const sel = this.selectedLive();
    if (!sel) return;
    this.store.restoreBarDrop(sel.uid, conductor);
    this.refreshBom();
  }

  wireEndpointLabel(uid: string): string {
    if (uid === 'N-BAR') return 'N шина';
    if (uid === 'PE-BAR') return 'PE шина';
    const comp = this.store.components().find((c) => c.uid === uid);
    return comp ? `${comp.label} — ${comp.name}` : uid;
  }

  onRemove(uid: string): void {
    this.store.removeComponent(uid);
    if (this.selected()?.uid === uid) this.selected.set(null);
    this.refreshBom();
  }

  removeSelected(): void {
    const sel = this.selected();
    if (sel) this.onRemove(sel.uid);
  }

  updateSelectedLabel(label: string): void {
    const sel = this.selected();
    if (sel) {
      this.store.updateLabel(sel.uid, label);
      this.selected.set({ ...sel, label });
    }
  }

  autoArrange(): void {
    this.store.autoArrange();
  }

  generateWiring(): void {
    if (!this.store.components().length) {
      this.flash('Добавете апарати преди да генерирате окабеляване.');
      return;
    }
    this.store.generateWiring();
    this.setLayerPreset('wiring');
    const wiring = this.store.wiring();
    this.flash(
      `Окабеляването е генерирано: ${wiring.wires.length} проводника, ${wiring.busbars.length} гребенни шини, ${wiring.circuits.length} кръга.`
    );
    this.showCanvas();
    this.refreshBom();
  }

  recomputeWiring(): void {
    if (!this.store.components().length) {
      this.flash('Добавете апарати преди да генерирате окабеляване.');
      return;
    }
    this.store.generateWiring(true);
    this.setLayerPreset('wiring');
    this.flash('Връзките са преизчислени по разположението в таблото.');
    this.showCanvas();
    this.refreshBom();
  }

  clearWiring(): void {
    this.store.clearWiring();
    this.refreshBom();
  }

  toggleLayer(id: EditorLayerId): void {
    this.layers.update((current) => ({ ...current, [id]: !current[id] }));
  }

  setLayerPreset(preset: 'all' | 'devices' | 'wiring'): void {
    if (preset === 'all') {
      this.layers.set({ ...DEFAULT_EDITOR_LAYERS, labels: this.layers().labels });
      return;
    }
    if (preset === 'devices') {
      this.layers.set({
        enclosure: true,
        devices: true,
        cables: false,
        comb: false,
        bars: false,
        labels: false,
      });
      return;
    }
    this.layers.set({
      enclosure: true,
      devices: true,
      cables: true,
      comb: true,
      bars: true,
      labels: true,
    });
  }

  updateCircuitName(id: string, name: string): void {
    this.store.updateCircuit(id, { name });
  }

  updateCircuitLength(id: string, value: string): void {
    const lengthM = Number(value);
    if (Number.isFinite(lengthM) && lengthM > 0) {
      this.store.updateCircuit(id, { lengthM });
    }
  }

  updateCircuitLoad(id: string, value: string): void {
    const loadKw = Number(value);
    if (Number.isFinite(loadKw) && loadKw >= 0) {
      this.store.updateCircuit(id, { loadKw });
    }
  }

  updateCircuitCrossSection(id: string, value: string): void {
    const mm2 = Number(value);
    if (Number.isFinite(mm2) && mm2 > 0) {
      this.store.updateCircuit(id, { cableCrossSectionMm2: mm2 });
    }
  }

  updateCircuitCableKind(id: string, kind: string): void {
    if (kind) this.store.updateCircuit(id, { cableKind: kind });
  }

  circuitKind(circuit: Circuit): string {
    return circuit.cableKind ?? parseCableKind(circuit.cableType);
  }

  cableSectionsFor(circuit: Circuit): number[] {
    const current = circuit.cableCrossSectionMm2;
    if (this.cableSections.includes(current)) return this.cableSections;
    return [...this.cableSections, current].sort((a, b) => a - b);
  }

  cableKindsFor(circuit: Circuit): typeof CABLE_KINDS {
    const kind = this.circuitKind(circuit);
    if (this.cableKinds.some((k) => k.id === kind)) return this.cableKinds;
    return [{ id: kind, label: kind, hint: 'От проекта' }, ...this.cableKinds];
  }

  /** Replaces the current layout with the configurator's proposal. */
  applyConfiguration(result: ConfiguratorResult): void {
    if (!result.enclosure) {
      this.flash('Конфигураторът не намери подходящо табло. Намалете товарите.');
      return;
    }

    this.store.reset();
    this.store.setEnclosure({
      catalogNumber: result.enclosure.catalogNumber,
      name: result.enclosure.name,
      rows: result.enclosure.rows,
      modulesPerRow: result.enclosure.modulesPerRow,
      supplySystem: 'TN-C-S',
      phases: 3,
      ipRating: 'IP40',
      thermalLimitW: this.thermalLimitFor(result.enclosure.rows, result.enclosure.modulesPerRow),
      ambientTempC: 30,
    });

    const catalog = this.productMap();
    let placed = 0;
    for (const device of result.devices) {
      const product = catalog.get(device.catalogNumber);
      if (!product) continue;
      if (this.store.addProduct(product, device.label)) placed += 1;
    }

    this.store.setLegend(
      result.devices.map((d) => ({ label: d.label, description: d.purpose }))
    );

    this.store.generateWiring();

    const legend = new Map(result.devices.map((d) => [d.label, d.purpose]));
    for (const circuit of this.store.circuits()) {
      const device = this.store.components().find((c) => c.uid === circuit.protectiveDeviceUid);
      const purpose = device ? legend.get(device.label) : undefined;
      if (purpose) {
        this.store.updateCircuit(circuit.id, {
          name: purpose,
          description: `${device?.label ?? ''} · ${device?.name ?? ''}`.trim(),
        });
      }
    }

    this.workspace.set('layout');
    this.activePanel.set('catalog');
    this.setLayerPreset('wiring');
    this.showCanvas();
    this.refreshBom();
    this.flash(`Таблото е генерирано: ${placed} апарата в ${result.enclosure.name}. Вижте разположението в центъра.`);
  }

  private thermalLimitFor(rows: number, modulesPerRow: number): number {
    return Math.round(20 + rows * modulesPerRow * 1.4);
  }

  setWorkspace(view: Workspace): void {
    this.workspace.set(view);
  }

  undo(): void {
    this.store.undo();
    this.refreshBom();
  }

  redo(): void {
    this.store.redo();
    this.refreshBom();
  }

  saveProject(): void {
    const payload = {
      name: this.projectName(),
      client_name: this.clientName() || null,
      panel_config: this.store.enclosure(),
      design_data: this.store.designForSave(),
    };
    const id = this.currentProjectId();
    if (id) {
      this.api.updateProject(id, payload).subscribe(() => {
        this.flash('Проектът е запазен.');
        this.loadProjects();
      });
    } else {
      this.api.createProject(payload).subscribe((proj) => {
        this.currentProjectId.set(proj.id);
        this.flash('Проектът е създаден.');
        this.loadProjects();
      });
    }
  }

  newProject(): void {
    this.currentProjectId.set(null);
    this.projectName.set('Ново табло');
    this.clientName.set('');
    this.store.reset();
    this.selected.set(null);
    this.selectedWireId.set(null);
    this.connectMode.set(false);
    this.bom.set(null);
  }

  openProject(id: number): void {
    this.api.getProject(id).subscribe((proj) => {
      this.currentProjectId.set(proj.id);
      this.projectName.set(proj.name);
      this.clientName.set(proj.client_name ?? '');
      this.store.load(proj.design_data);
      this.activePanel.set('catalog');
      this.refreshBom();
      this.flash(`Зареден проект: ${proj.name}`);
    });
  }

  duplicateProject(id: number, event: Event): void {
    event.stopPropagation();
    this.api.duplicateProject(id).subscribe(() => {
      this.loadProjects();
      this.flash('Проектът е дублиран.');
    });
  }

  deleteProject(id: number, event: Event): void {
    event.stopPropagation();
    this.api.deleteProject(id).subscribe(() => {
      if (this.currentProjectId() === id) this.newProject();
      this.loadProjects();
      this.flash('Проектът е изтрит.');
    });
  }

  saveVersion(): void {
    const id = this.currentProjectId();
    if (!id) {
      this.flash('Първо запазете проекта.');
      return;
    }
    this.api.updateProject(id, {
      name: this.projectName(),
      design_data: this.store.designForSave(),
      panel_config: this.store.enclosure(),
    }).subscribe(() => {
      this.api.createVersion(id).subscribe(() => this.flash('Създадена е нова версия.'));
    });
  }

  refreshBom(): void {
    const id = this.currentProjectId();
    if (id) {
      this.api.getBom(id).subscribe((b) => this.bom.set(b));
    } else {
      this.bom.set(this.computeLocalBom());
    }
  }

  private computeLocalBom(): Bom {
    const counts = new Map<string, number>();
    for (const c of this.store.components()) {
      counts.set(c.catalogNumber, (counts.get(c.catalogNumber) ?? 0) + 1);
    }

    // Wiring parts are physical items and belong in the BOM. A comb busbar is
    // sold in 12-module lengths, so a longer run needs several pieces.
    const wiring = this.store.wiring();
    for (const busbar of wiring.busbars) {
      const pieces = Math.max(1, Math.ceil(busbar.spanModules / 12));
      counts.set(busbar.catalogNumber, (counts.get(busbar.catalogNumber) ?? 0) + pieces);
    }
    for (const bar of wiring.bars) {
      const pieces = Math.max(1, Math.ceil(bar.usedWays / 12));
      counts.set(bar.catalogNumber, (counts.get(bar.catalogNumber) ?? 0) + pieces);
    }

    const enclosure = this.store.enclosure();
    if (enclosure.catalogNumber && this.productMap().has(enclosure.catalogNumber)) {
      counts.set(enclosure.catalogNumber, (counts.get(enclosure.catalogNumber) ?? 0) + 1);
    }
    const items = Array.from(counts.entries()).map(([catalog, qty]) => {
      const p = this.productMap().get(catalog);
      const unit = Number(p?.price ?? 0);
      return {
        catalog_number: catalog,
        name: p?.name ?? catalog,
        series: p?.series ?? null,
        quantity: qty,
        unit: 'бр.',
        unit_price: unit,
        line_total: Math.round(unit * qty * 100) / 100,
        currency: p?.currency ?? 'EUR',
        verified: p?.verified ?? false,
      };
    });
    const subtotal = items.reduce((s, i) => s + i.line_total, 0);
    return {
      items,
      totals: {
        subtotal: Math.round(subtotal * 100) / 100,
        currency: 'EUR',
        item_count: items.length,
        component_count: this.store.components().length,
      },
      legend: [],
    };
  }

  private loadDevicePhoto(code: string): void {
    if (!code || this.photoLoading.has(code) || this.devicePhotos()[code]) return;
    this.photoLoading.add(code);
    this.api.productPhoto(code).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        this.devicePhotos.update((current) => ({ ...current, [code]: url }));
      },
      error: () => {
        this.photoLoading.delete(code);
      },
    });
  }

  export(type: 'pdf' | 'csv' | 'excel'): void {
    const id = this.currentProjectId();
    if (!id) {
      this.flash('Първо запазете проекта, за да експортирате.');
      return;
    }
    this.api.downloadExport(id, type).subscribe({
      next: (blob) => {
        const ext = type === 'excel' ? 'xlsx' : type;
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `project-${id}.${ext}`;
        a.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.flash('Експортът не успя. Проверете дали сте влезли.'),
    });
  }

  onEplanFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.api.importEplan(file).subscribe({
      next: () => {
        this.flash('EPLAN каталогът е импортиран.');
        this.loadCatalog();
      },
      error: () => this.flash('Грешка при импорт на EPLAN файла.'),
    });
  }

  num(value: string | number | null): string {
    if (value === null || value === undefined) return '—';
    return String(value);
  }

  private loadCreatedBoards(): void {
    const offer = this.offerStore.load();
    if (!offer?.boards.length) return;
    const codes = [...new Set(offer.boards.flatMap((board) => board.lines.map((line) => line.catalog_number)))];
    if (!codes.length) return;

    forkJoin({
      enclosures: this.api.enclosures(),
      products: forkJoin(
        codes.map((code) =>
          this.api.getProducts({ search: code, per_page: 8 }).pipe(
            map((res) => res.data.find((product) => product.catalog_number === code) ?? null),
            catchError(() => of(null)),
          ),
        ),
      ),
      faces: forkJoin(
        codes.map((code) =>
          this.api.productFace(code).pipe(
            map((face) => face.width_mm),
            catchError(() => of(null as number | null)),
          ),
        ),
      ),
    }).subscribe(({ enclosures, products, faces }) => {
      const options = enclosures.enclosures;
      if (!options.length) return;
      const choices = loadEnclosureChoices();
      const productOf = new Map(codes.map((code, index) => [code, products[index]]));
      const modulesOf = new Map(
        codes.map((code, index) => {
          const width = faces[index];
          const modules = width != null ? deviceModules(width) : Math.max(1, products[index]?.width_modules || 1);
          return [code, modules];
        }),
      );
      const built = offer.boards
        .filter((board) => board.lines.length)
        .map((board) => {
          let widest = 1;
          let total = 0;
          for (const line of board.lines) {
            const modules = modulesOf.get(line.catalog_number) ?? 1;
            widest = Math.max(widest, modules);
            total += modules * line.quantity;
          }
          const stored = options.find((option) => option.catalog_number === choices[board.name]);
          const option = stored ?? suggestEnclosure(options, total, widest) ?? options[0];
          return designFromOfferBoard(
            board,
            option,
            (code) => modulesOf.get(code) ?? 1,
            (code) => productOf.get(code) ?? null,
          );
        });
      if (!built.length) return;
      this.createdBoards.set(built);
      this.showCreated(built[0].name);
    });
  }

  private flash(message: string): void {
    this.statusMessage.set(message);
    setTimeout(() => this.statusMessage.set(''), 4000);
  }
}
