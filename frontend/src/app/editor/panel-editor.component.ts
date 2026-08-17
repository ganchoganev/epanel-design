import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import Konva from 'konva';
import { DesignStore } from '../services/design-store.service';
import {
  BusbarRun,
  CONDUCTOR_COLORS,
  DEFAULT_EDITOR_LAYERS,
  EditorLayers,
  PlacedComponent,
  Wire,
} from '../models/project.models';

const MODULE_PX = 40;
const ROW_HEIGHT = 108;
const ROW_GAP = 52;
const PADDING = 28;

/** Height of the busbar drawn immediately under a run of devices. */
const BUSBAR_HEIGHT = 7;

/** Vertical space reserved below the last rail for the N and PE bars. */
const BAR_ZONE_HEIGHT = 72;

const MIN_ZOOM = 0.4;
const MAX_ZOOM = 4;
const ZOOM_STEP = 1.12;

@Component({
  selector: 'app-panel-editor',
  standalone: true,
  template: `
    <div class="editor-wrap">
      <div #host class="editor-host" [class.panning]="panning()" [class.connect-mode]="connectMode()"></div>
      <div class="zoom-bar">
        <button type="button" (click)="zoomBy(-1)" title="Намали — колелце надолу">−</button>
        <button type="button" class="zoom-pct" (click)="resetZoom()" title="Реален размер 100%">
          {{ zoomPercent() }}%
        </button>
        <button type="button" (click)="zoomBy(1)" title="Увеличи — колелце нагоре">+</button>
        <button type="button" class="zoom-fit" (click)="fitToView()" title="Побери таблото в екрана">
          Побери
        </button>
      </div>
      <div class="zoom-hint">колелце / щипка = зуум · влачене по празно = местене</div>
      @if (connectMode()) {
        <div class="connect-hint">
          {{ connectFromUid() ? 'Кликни приемника (който се захранва)' : 'Кликни източника, после приемника' }}
        </div>
      }
    </div>
  `,
  styles: [
    `
      :host {
        display: block;
        position: relative;
        height: 100%;
        min-height: 0;
      }
      .editor-wrap {
        position: relative;
        width: 100%;
        height: 100%;
        min-height: 0;
      }
      .editor-host {
        width: 100%;
        height: 100%;
        background:
          linear-gradient(90deg, rgba(0, 0, 0, 0.04) 1px, transparent 1px) 0 0 / 40px 40px,
          linear-gradient(rgba(0, 0, 0, 0.04) 1px, transparent 1px) 0 0 / 40px 40px,
          #eef1f4;
        overflow: hidden;
        cursor: grab;
        touch-action: none;
        -webkit-user-select: none;
        user-select: none;
      }
      .editor-host.connect-mode {
        cursor: crosshair;
      }
      .editor-host.panning {
        cursor: grabbing;
      }
      .zoom-bar {
        position: absolute;
        right: 12px;
        bottom: 12px;
        display: flex;
        align-items: center;
        gap: 2px;
        background: #fff;
        border: 1px solid #cfd8dc;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        padding: 4px;
        z-index: 3;
      }
      .zoom-bar button {
        border: none;
        background: transparent;
        min-width: 40px;
        height: 40px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 18px;
        color: #37474f;
      }
      .zoom-bar button:hover {
        background: #eceff1;
      }
      .zoom-pct {
        font-size: 12px !important;
        font-weight: 600;
        min-width: 52px !important;
      }
      .zoom-fit {
        font-size: 12px !important;
        padding: 0 8px;
        border-left: 1px solid #eceff1 !important;
        border-radius: 0 5px 5px 0 !important;
      }
      .zoom-hint {
        position: absolute;
        left: 12px;
        bottom: 14px;
        font-size: 11px;
        color: #90a4ae;
        pointer-events: none;
        z-index: 3;
      }
      .connect-hint {
        position: absolute;
        left: 50%;
        top: 10px;
        transform: translateX(-50%);
        background: #1565c0;
        color: #fff;
        font-size: 13px;
        font-weight: 600;
        padding: 8px 14px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(21, 101, 192, 0.35);
        z-index: 4;
        pointer-events: none;
        white-space: nowrap;
      }
      @media (max-width: 960px) {
        .zoom-hint {
          display: none;
        }
        .zoom-bar {
          right: 8px;
          bottom: 8px;
        }
      }
    `,
  ],
})
export class PanelEditorComponent implements AfterViewInit, OnDestroy {
  private host = viewChild.required<ElementRef<HTMLDivElement>>('host');
  private store = inject(DesignStore);

  readonly selectComponent = output<PlacedComponent | null>();
  readonly removeRequest = output<string>();
  readonly selectWire = output<string | null>();
  readonly connectionChange = output<string>();

  /** CAD-style visibility of drawing layers. */
  readonly layers = input<EditorLayers>(DEFAULT_EDITOR_LAYERS);
  readonly connectMode = input(false);
  readonly selectedWireId = input<string | null>(null);

  private stage?: Konva.Stage;
  private layer?: Konva.Layer;
  private wiringLayer?: Konva.Layer;
  private deviceLayer?: Konva.Layer;
  private ghostLayer?: Konva.Layer;

  readonly zoomPercent = signal(100);
  readonly panning = signal(false);
  readonly connectFromUid = signal<string | null>(null);

  private previewPositions = new Map<string, { row: number; startModule: number }>();
  private dragging = false;

  private spaceDown = false;
  private isPanning = false;
  private panLast = { x: 0, y: 0 };
  private lastEnclosureKey = '';
  private hasFitted = false;
  private lastPinchDist = 0;
  private resizeObserver?: ResizeObserver;

  constructor() {
    effect(() => {
      this.store.design();
      this.layers();
      this.connectMode();
      this.selectedWireId();
      if (!this.connectMode()) this.connectFromUid.set(null);
      queueMicrotask(() => this.render());
    });
  }

  ngAfterViewInit(): void {
    const el = this.host().nativeElement;
    this.stage = new Konva.Stage({
      container: el,
      width: el.clientWidth || 1000,
      height: el.clientHeight || 600,
    });
    this.layer = new Konva.Layer();
    this.wiringLayer = new Konva.Layer({ listening: true });
    this.deviceLayer = new Konva.Layer();
    this.ghostLayer = new Konva.Layer();
    this.stage.add(this.layer);
    this.stage.add(this.wiringLayer);
    this.stage.add(this.deviceLayer);
    this.stage.add(this.ghostLayer);

    el.addEventListener('wheel', this.onWheel, { passive: false });
    el.addEventListener('mousedown', this.onHostMouseDown);
    el.addEventListener('touchstart', this.onTouchStart, { passive: false });
    el.addEventListener('touchmove', this.onTouchMove, { passive: false });
    el.addEventListener('touchend', this.onTouchEnd);
    el.addEventListener('touchcancel', this.onTouchEnd);
    window.addEventListener('mousemove', this.onPanMove);
    window.addEventListener('mouseup', this.onPanEnd);
    window.addEventListener('keydown', this.onKeyDown);
    window.addEventListener('keyup', this.onKeyUp);
    window.addEventListener('resize', this.onResize);
    el.addEventListener('contextmenu', this.preventMenu);

    this.resizeObserver = new ResizeObserver(() => this.onResize());
    this.resizeObserver.observe(el);

    this.stage.on('dblclick dbltap', (evt) => {
      if (this.isDeviceTarget(evt.target)) return;
      this.fitToView();
    });

    this.render();
    this.fitToView();
  }

  ngOnDestroy(): void {
    const el = this.host()?.nativeElement;
    el?.removeEventListener('wheel', this.onWheel);
    el?.removeEventListener('mousedown', this.onHostMouseDown);
    el?.removeEventListener('touchstart', this.onTouchStart);
    el?.removeEventListener('touchmove', this.onTouchMove);
    el?.removeEventListener('touchend', this.onTouchEnd);
    el?.removeEventListener('touchcancel', this.onTouchEnd);
    el?.removeEventListener('contextmenu', this.preventMenu);
    window.removeEventListener('mousemove', this.onPanMove);
    window.removeEventListener('mouseup', this.onPanEnd);
    window.removeEventListener('keydown', this.onKeyDown);
    window.removeEventListener('keyup', this.onKeyUp);
    window.removeEventListener('resize', this.onResize);
    this.resizeObserver?.disconnect();
    this.stage?.destroy();
  }

  zoomBy(direction: 1 | -1): void {
    if (!this.stage) return;
    const center = {
      x: this.stage.width() / 2,
      y: this.stage.height() / 2,
    };
    this.zoomAt(center.x, center.y, direction > 0 ? ZOOM_STEP : 1 / ZOOM_STEP);
  }

  resetZoom(): void {
    if (!this.stage) return;
    this.stage.scale({ x: 1, y: 1 });
    this.stage.position({ x: 24, y: 16 });
    this.syncZoomLabel();
    this.stage.batchDraw();
  }

  fitToView(): void {
    if (!this.stage) return;
    const { width, height } = this.panelSize();
    const viewW = this.stage.width();
    const viewH = this.stage.height();
    if (viewW < 40 || viewH < 40) return;

    const pad = 48;
    const scale = Math.min((viewW - pad) / width, (viewH - pad) / height);
    const clamped = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, scale));
    this.stage.scale({ x: clamped, y: clamped });
    this.stage.position({
      x: (viewW - width * clamped) / 2,
      y: (viewH - height * clamped) / 2,
    });
    this.syncZoomLabel();
    this.stage.batchDraw();
    this.hasFitted = true;
  }

  private onResize = () => {
    if (!this.stage) return;
    this.resizeStageToHost();
    if (!this.hasFitted) this.fitToView();
    this.stage.batchDraw();
  };

  private preventMenu = (e: Event) => e.preventDefault();

  private onWheel = (e: WheelEvent) => {
    e.preventDefault();
    this.zoomAt(e.offsetX, e.offsetY, e.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP);
  };

  private onHostMouseDown = (e: MouseEvent) => {
    if (!this.stage) return;
    const onInteractive = this.hitDeviceAt(e.clientX, e.clientY) || this.hitWireAt(e.clientX, e.clientY);
    const middle = e.button === 1;
    const right = e.button === 2;
    const emptyLeft = e.button === 0 && (!onInteractive || this.spaceDown);
    if (!middle && !right && !emptyLeft && !this.spaceDown) return;
    if (onInteractive && e.button === 0 && !this.spaceDown) return;

    e.preventDefault();
    this.startPan(e.clientX, e.clientY);
  };

  private onTouchStart = (e: TouchEvent) => {
    if (e.touches.length >= 2) {
      e.preventDefault();
      this.isPanning = false;
      this.panning.set(false);
      this.lastPinchDist = this.touchDistance(e);
      return;
    }
    if (e.touches.length !== 1) return;
    const touch = e.touches[0];
    if (this.hitDeviceAt(touch.clientX, touch.clientY)) return;
    e.preventDefault();
    this.startPan(touch.clientX, touch.clientY);
  };

  private onTouchMove = (e: TouchEvent) => {
    if (e.touches.length >= 2) {
      e.preventDefault();
      const dist = this.touchDistance(e);
      if (this.lastPinchDist > 0) {
        const factor = dist / this.lastPinchDist;
        const center = this.touchCenter(e);
        this.zoomAt(center.x, center.y, factor);
      }
      this.lastPinchDist = dist;
      this.isPanning = false;
      return;
    }
    if (!this.isPanning || e.touches.length !== 1) return;
    e.preventDefault();
    this.movePan(e.touches[0].clientX, e.touches[0].clientY);
  };

  private onTouchEnd = (e: TouchEvent) => {
    if (e.touches.length < 2) this.lastPinchDist = 0;
    if (e.touches.length === 0) this.onPanEnd();
  };

  private startPan(clientX: number, clientY: number): void {
    this.isPanning = true;
    this.panning.set(true);
    this.panLast = { x: clientX, y: clientY };
  }

  private movePan(clientX: number, clientY: number): void {
    if (!this.stage) return;
    const dx = clientX - this.panLast.x;
    const dy = clientY - this.panLast.y;
    this.panLast = { x: clientX, y: clientY };
    this.stage.position({
      x: this.stage.x() + dx,
      y: this.stage.y() + dy,
    });
    this.stage.batchDraw();
  }

  private onPanMove = (e: MouseEvent) => {
    if (!this.isPanning || !this.stage) return;
    this.movePan(e.clientX, e.clientY);
  };

  private onPanEnd = () => {
    this.isPanning = false;
    this.panning.set(false);
  };

  private onKeyDown = (e: KeyboardEvent) => {
    if (e.code === 'Space' && !this.isTyping(e)) {
      e.preventDefault();
      this.spaceDown = true;
    }
    if (this.isTyping(e) || !this.stage) return;
    if (e.key === '+' || e.key === '=') this.zoomBy(1);
    if (e.key === '-' || e.key === '_') this.zoomBy(-1);
    if (e.key === '0') this.resetZoom();
    if (e.key === 'f' || e.key === 'F') this.fitToView();
    if (e.key === 'Escape') {
      this.connectFromUid.set(null);
      this.selectWire.emit(null);
      this.render();
    }
    if ((e.key === 'Delete' || e.key === 'Backspace') && this.selectedWireId()) {
      e.preventDefault();
      this.store.removeWire(this.selectedWireId()!);
      this.selectWire.emit(null);
    }
  };

  private onKeyUp = (e: KeyboardEvent) => {
    if (e.code === 'Space') this.spaceDown = false;
  };

  private isTyping(e: KeyboardEvent): boolean {
    const el = e.target as HTMLElement | null;
    const tag = el?.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || !!el?.isContentEditable;
  }

  private hitDeviceAt(clientX: number, clientY: number): boolean {
    if (!this.stage) return false;
    const rect = this.host().nativeElement.getBoundingClientRect();
    const pos = { x: clientX - rect.left, y: clientY - rect.top };
    const shape = this.stage.getIntersection(pos);
    return this.isDeviceTarget(shape);
  }

  private touchDistance(e: TouchEvent): number {
    const a = e.touches[0];
    const b = e.touches[1];
    return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
  }

  private touchCenter(e: TouchEvent): { x: number; y: number } {
    const rect = this.host().nativeElement.getBoundingClientRect();
    return {
      x: (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left,
      y: (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top,
    };
  }

  private isDeviceTarget(node: Konva.Node | null | undefined): boolean {
    let current: Konva.Node | null | undefined = node;
    while (current && current !== this.stage) {
      if (current.getParent() === this.deviceLayer) return true;
      if (current.draggable()) return true;
      current = current.getParent();
    }
    return false;
  }

  private isWireTarget(node: Konva.Node | null | undefined): boolean {
    let current: Konva.Node | null | undefined = node;
    while (current && current !== this.stage) {
      if (current.getAttr('wireId')) return true;
      current = current.getParent();
    }
    return false;
  }

  private hitWireAt(clientX: number, clientY: number): boolean {
    if (!this.stage) return false;
    const rect = this.host().nativeElement.getBoundingClientRect();
    const pos = { x: clientX - rect.left, y: clientY - rect.top };
    return this.isWireTarget(this.stage.getIntersection(pos));
  }

  private zoomAt(pointerX: number, pointerY: number, factor: number): void {
    if (!this.stage) return;
    const oldScale = this.stage.scaleX();
    const scale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, oldScale * factor));
    const mousePointTo = {
      x: (pointerX - this.stage.x()) / oldScale,
      y: (pointerY - this.stage.y()) / oldScale,
    };
    this.stage.scale({ x: scale, y: scale });
    this.stage.position({
      x: pointerX - mousePointTo.x * scale,
      y: pointerY - mousePointTo.y * scale,
    });
    this.syncZoomLabel();
    this.stage.batchDraw();
  }

  private syncZoomLabel(): void {
    this.zoomPercent.set(Math.round((this.stage?.scaleX() ?? 1) * 100));
  }

  private resizeStageToHost(): void {
    if (!this.stage) return;
    const el = this.host().nativeElement;
    this.stage.width(el.clientWidth || 1000);
    this.stage.height(el.clientHeight || 600);
  }

  private panelSize(): { width: number; height: number } {
    const enclosure = this.store.design().enclosure;
    return {
      width: enclosure.modulesPerRow * MODULE_PX + PADDING * 2 + 20,
      height:
        enclosure.rows * ROW_HEIGHT +
        (enclosure.rows - 1) * ROW_GAP +
        PADDING * 2 +
        40 +
        BAR_ZONE_HEIGHT,
    };
  }

  private render(): void {
    if (!this.layer || !this.stage) return;
    if (this.dragging) {
      this.redrawWiring();
      return;
    }
    const design = this.store.design();
    this.layer.destroyChildren();
    this.wiringLayer?.destroyChildren();
    this.deviceLayer?.destroyChildren();

    const { rows, modulesPerRow } = {
      rows: design.enclosure.rows,
      modulesPerRow: design.enclosure.modulesPerRow,
    };

    const panelWidth = modulesPerRow * MODULE_PX + PADDING * 2;
    const panelHeight =
      rows * ROW_HEIGHT + (rows - 1) * ROW_GAP + PADDING * 2 + 20 + BAR_ZONE_HEIGHT;
    this.resizeStageToHost();

    const enclosureKey = `${rows}x${modulesPerRow}`;
    const enclosureChanged = enclosureKey !== this.lastEnclosureKey;
    this.lastEnclosureKey = enclosureKey;

    const visible = this.layers();

    if (visible.enclosure) {
      this.layer.add(
        new Konva.Rect({
          x: 10,
          y: 10,
          width: panelWidth,
          height: panelHeight,
          stroke: '#37474f',
          strokeWidth: 2,
          cornerRadius: 6,
          fill: '#ffffff',
        })
      );

      this.layer.add(
        new Konva.Text({
          x: 18,
          y: 16,
          text: `${design.enclosure.name}  —  ${modulesPerRow} мод. x ${rows} реда`,
          fontSize: 12,
          fontStyle: 'bold',
          fill: '#546e7a',
        })
      );

      for (let row = 0; row < rows; row++) {
        const y = this.rowY(row);
        this.layer.add(
          new Konva.Rect({
            x: PADDING + 10,
            y: y + ROW_HEIGHT / 2 - 6,
            width: modulesPerRow * MODULE_PX,
            height: 12,
            fill: '#cfd8dc',
            stroke: '#90a4ae',
            strokeWidth: 1,
          })
        );
        for (let m = 0; m <= modulesPerRow; m++) {
          this.layer.add(
            new Konva.Line({
              points: [
                PADDING + 10 + m * MODULE_PX,
                y + 6,
                PADDING + 10 + m * MODULE_PX,
                y + ROW_HEIGHT - 6,
              ],
              stroke: '#eceff1',
              strokeWidth: 1,
            })
          );
        }
        this.layer.add(
          new Konva.Text({
            x: 14,
            y: y + ROW_HEIGHT / 2 - 6,
            text: `DIN ${row + 1}`,
            fontSize: 9,
            fill: '#b0bec5',
          })
        );
      }
    }

    this.renderWiring(panelWidth, visible);

    if (visible.devices) {
      for (const comp of design.components) {
        this.deviceLayer?.add(this.buildComponentNode(comp));
      }
    }

    this.layer.draw();
    this.wiringLayer?.draw();
    this.deviceLayer?.draw();

    if (enclosureChanged || !this.hasFitted) {
      this.fitToView();
    }
  }

  private overlayPositions(components: PlacedComponent[]): Map<string, PlacedComponent> {
    return new Map(
      components.map((c) => {
        const preview = this.previewPositions.get(c.uid);
        return [c.uid, preview ? { ...c, ...preview } : c];
      })
    );
  }

  private redrawWiring(): void {
    if (!this.wiringLayer) return;
    this.wiringLayer.destroyChildren();
    const enclosure = this.store.design().enclosure;
    const panelWidth = enclosure.modulesPerRow * MODULE_PX + PADDING * 2;
    this.renderWiring(panelWidth, this.layers());
    this.wiringLayer.draw();
  }

  private renderWiring(panelWidth: number, visible: EditorLayers): void {
    if (!this.wiringLayer) return;
    const design = this.store.design();
    const wiring = design.wiring;
    if (!wiring.generatedAt) return;

    const byUid = this.overlayPositions(design.components);

    if (visible.comb) {
      for (const busbar of wiring.busbars) {
        this.wiringLayer.add(this.buildBusbarNode(busbar, byUid));
      }
    }

    if (visible.cables || visible.bars) {
      for (const wire of wiring.wires) {
        const isBarDrop =
          wire.from.componentUid === 'PE-BAR' || wire.from.componentUid === 'N-BAR';
        if (isBarDrop && !visible.bars) continue;
        if (!isBarDrop && !visible.cables) continue;
        const node = this.buildWireNode(wire, byUid, visible.labels);
        if (node) this.wiringLayer.add(node);
      }
    }

    if (visible.bars && wiring.bars.length) {
      this.renderDistributionBars(panelWidth);
    }
  }

  /**
   * A comb busbar is drawn as a coloured bar tucked under the top terminals of
   * the devices it feeds, with one stripe per phase, mirroring the real part.
   */
  private buildBusbarNode(busbar: BusbarRun, byUid: Map<string, PlacedComponent>): Konva.Group {
    const group = new Konva.Group();
    const members = busbar.targetUids
      .map((id) => byUid.get(id))
      .filter((c): c is PlacedComponent => !!c);
    const startModule = members.length
      ? Math.min(...members.map((c) => c.startModule))
      : busbar.startModule;
    const end = members.length
      ? Math.max(...members.map((c) => c.startModule + c.widthModules))
      : busbar.startModule + busbar.spanModules;
    const row = members[0]?.row ?? busbar.row;
    const x = PADDING + 10 + startModule * MODULE_PX;
    const width = Math.max(MODULE_PX, (end - startModule) * MODULE_PX);
    const baseY = this.rowY(row) + 4;

    const stripes: Array<keyof typeof CONDUCTOR_COLORS> =
      busbar.phases === 3 ? ['L1', 'L2', 'L3'] : ['L1'];

    stripes.forEach((conductor, i) => {
      group.add(
        new Konva.Rect({
          x,
          y: baseY + i * (BUSBAR_HEIGHT - 1),
          width,
          height: BUSBAR_HEIGHT,
          fill: CONDUCTOR_COLORS[conductor],
          opacity: 0.85,
          cornerRadius: 2,
        })
      );
    });

    group.add(
      new Konva.Text({
        x: x + width + 6,
        y: baseY - 1,
        text: `${busbar.name} · ${Math.max(1, end - startModule)} мод.`,
        fontSize: 8,
        fill: '#78909c',
      })
    );

    // Pin stubs showing where the busbar engages each device terminal.
    for (const uid of busbar.targetUids) {
      const comp = byUid.get(uid);
      if (!comp) continue;
      const pinX = PADDING + 10 + (comp.startModule + comp.widthModules / 2) * MODULE_PX;
      group.add(
        new Konva.Line({
          points: [pinX, baseY + stripes.length * BUSBAR_HEIGHT, pinX, this.rowY(comp.row) + 10],
          stroke: CONDUCTOR_COLORS.L1,
          strokeWidth: 2,
          opacity: 0.7,
        })
      );
    }

    return group;
  }

  /**
   * Wires are routed orthogonally: down out of the source, across the wiring
   * channel between rails, then up into the destination. This matches how the
   * cables actually lie in the enclosure and keeps crossings readable.
   */
  private buildWireNode(
    wire: Wire,
    byUid: Map<string, PlacedComponent>,
    showNumbers: boolean
  ): Konva.Group | null {
    const from = byUid.get(wire.from.componentUid);
    const to = byUid.get(wire.to.componentUid);
    const group = new Konva.Group();
    group.setAttr('wireId', wire.id);
    const selected = this.selectedWireId() === wire.id;
    const strokeWidth = selected ? 4.2 : 2.2;
    const opacity = selected ? 1 : 0.9;

    // N / PE drops run from the distribution bars at the bottom of the enclosure.
    if (wire.from.componentUid === 'PE-BAR' || wire.from.componentUid === 'N-BAR') {
      if (!to) return null;
      const barKind = wire.from.componentUid === 'PE-BAR' ? 'PE' : 'N';
      const x =
        PADDING +
        10 +
        (to.startModule + to.widthModules / 2) * MODULE_PX +
        (barKind === 'PE' ? 5 : -5);
      const deviceBottom = this.rowY(to.row) + 10 + (ROW_HEIGHT - 24);
      const barY = this.barY(barKind);
      group.add(
        new Konva.Line({
          points: [x, deviceBottom, x, barY],
          stroke: CONDUCTOR_COLORS[barKind],
          strokeWidth: selected ? 3.6 : 1.8,
          dash: barKind === 'PE' ? [4, 3] : undefined,
          opacity,
          hitStrokeWidth: 16,
        })
      );
      group.add(
        new Konva.Circle({
          x,
          y: barY,
          radius: 2.5,
          fill: CONDUCTOR_COLORS[barKind],
        })
      );
      this.bindWireSelect(group, wire.id);
      return group;
    }

    if (!from || !to) return null;

    const fromX = PADDING + 10 + (from.startModule + from.widthModules / 2) * MODULE_PX;
    const toX = PADDING + 10 + (to.startModule + to.widthModules / 2) * MODULE_PX;
    const fromY = this.rowY(from.row) + 10 + (ROW_HEIGHT - 24);
    const toY = this.rowY(to.row) + 10;

    // Offset per conductor so parallel wires of one cable stay distinguishable.
    const offset = this.conductorOffset(wire.conductor);
    const channelY =
      from.row === to.row
        ? Math.max(fromY, toY) + 12 + offset
        : this.rowY(Math.max(from.row, to.row)) - ROW_GAP / 2 + offset;

    group.add(
      new Konva.Line({
        points: [
          fromX + offset,
          fromY,
          fromX + offset,
          channelY,
          toX + offset,
          channelY,
          toX + offset,
          toY,
        ],
        stroke: wire.colorHex,
        strokeWidth,
        lineJoin: 'round',
        opacity,
        hitStrokeWidth: 16,
        shadowColor: selected ? wire.colorHex : undefined,
        shadowBlur: selected ? 8 : 0,
      })
    );

    group.add(
      new Konva.Circle({
        x: toX + offset,
        y: toY,
        radius: 2,
        fill: wire.colorHex,
      })
    );

    if (showNumbers) {
      const tag = new Konva.Label({ x: (fromX + toX) / 2 + offset - 12, y: channelY - 10 });
      tag.add(new Konva.Tag({ fill: '#ffffff', stroke: '#cfd8dc', cornerRadius: 2 }));
      tag.add(
        new Konva.Text({
          text: `${wire.wireNumber} ${wire.crossSectionMm2}mm²`,
          fontSize: 9,
          padding: 2,
          fill: '#455a64',
        })
      );
      group.add(tag);
    }

    this.bindWireSelect(group, wire.id);
    return group;
  }

  private bindWireSelect(group: Konva.Group, wireId: string): void {
    group.on('click tap', (evt) => {
      evt.cancelBubble = true;
      this.selectWire.emit(wireId);
      this.selectComponent.emit(null);
    });
  }

  private renderDistributionBars(panelWidth: number): void {
    if (!this.wiringLayer) return;
    const bars = this.store.design().wiring.bars;

    for (const bar of bars) {
      const y = this.barY(bar.conductor);
      this.wiringLayer.add(
        new Konva.Rect({
          x: PADDING + 10,
          y,
          width: panelWidth - PADDING * 2 + 10,
          height: 10,
          fill: CONDUCTOR_COLORS[bar.conductor],
          opacity: 0.95,
          cornerRadius: 2,
          stroke: '#263238',
          strokeWidth: 0.6,
        })
      );
        this.wiringLayer.add(
        new Konva.Text({
          x: bar.conductor === 'N' ? PADDING + 14 : panelWidth - 160,
          y: y - 13,
          width: 150,
          align: bar.conductor === 'N' ? 'left' : 'right',
          text: `${bar.conductor} · ${bar.usedWays}/${bar.ways} · ${bar.name}`,
          fontSize: 10,
          fontStyle: 'bold',
          fill: '#263238',
        })
      );
    }
  }

  private barY(conductor: 'N' | 'PE'): number {
    const rows = this.store.design().enclosure.rows;
    const base = this.rowY(rows - 1) + ROW_HEIGHT + 8;
    return conductor === 'N' ? base : base + 22;
  }

  private conductorOffset(conductor: Wire['conductor']): number {
    const order: Record<Wire['conductor'], number> = { L1: -3, L2: 0, L3: 3, N: 6, PE: 9 };
    return order[conductor] ?? 0;
  }

  private rowY(row: number): number {
    return 10 + PADDING + 14 + row * (ROW_HEIGHT + ROW_GAP);
  }

  private buildComponentNode(comp: PlacedComponent): Konva.Group {
    const x = PADDING + 10 + comp.startModule * MODULE_PX;
    const y = this.rowY(comp.row) + 10;
    const width = comp.widthModules * MODULE_PX;
    const height = ROW_HEIGHT - 24;

    const connecting = this.connectMode();
    const isSource = this.connectFromUid() === comp.uid;
    const group = new Konva.Group({
      x,
      y,
      draggable: !connecting,
      name: comp.uid,
    });

    const fill = isSource ? '#bbdefb' : comp.groupId ? '#e3f2fd' : '#ffffff';
    const stroke = isSource ? '#0d47a1' : comp.verified ? '#2e7d32' : '#e65100';

    const rect = new Konva.Rect({
      width,
      height,
      fill,
      stroke,
      strokeWidth: 1.5,
      cornerRadius: 4,
      shadowColor: 'rgba(0,0,0,0.15)',
      shadowBlur: 3,
      shadowOffsetY: 1,
    });
    group.add(rect);

    group.add(
      new Konva.Text({
        x: 3,
        y: 4,
        width: width - 6,
        text: comp.label,
        fontSize: 13,
        fontStyle: 'bold',
        fill: '#263238',
        align: 'center',
      })
    );
    group.add(
      new Konva.Text({
        x: 2,
        y: 22,
        width: width - 4,
        height: height - 28,
        text: comp.name,
        fontSize: 10,
        fill: '#546e7a',
        align: 'center',
        wrap: 'word',
      })
    );
    group.add(
      new Konva.Text({
        x: 2,
        y: height - 14,
        width: width - 4,
        text: `${comp.widthModules} мод`,
        fontSize: 9,
        fill: '#b0bec5',
        align: 'center',
      })
    );

    group.on('click tap', (evt) => {
      evt.cancelBubble = true;
      if (this.connectMode()) {
        this.handleConnectClick(comp);
        return;
      }
      this.selectWire.emit(null);
      this.selectComponent.emit(comp);
    });
    group.on('dblclick', () => {
      if (!this.connectMode()) this.removeRequest.emit(comp.uid);
    });

    group.on('dragstart', () => {
      this.dragging = true;
      rect.stroke('#1565c0');
    });

    group.on('dragmove', () => {
      const target = this.moduleFromPointer(group.x(), group.y());
      this.showGhost(comp, target);
      this.setPreviewFromDrag(comp, {
        row: target.row,
        startModule: (group.x() - (PADDING + 10)) / MODULE_PX,
      });
      this.redrawWiring();
    });

    group.on('dragend', () => {
      this.dragging = false;
      this.previewPositions.clear();
      this.ghostLayer?.destroyChildren();
      this.ghostLayer?.draw();
      const target = this.moduleFromPointer(group.x(), group.y());
      const moved = this.store.moveComponent(comp.uid, target.row, target.startModule);
      if (!moved) {
        this.render();
      }
    });

    return group;
  }

  private handleConnectClick(comp: PlacedComponent): void {
    const fromUid = this.connectFromUid();
    if (!fromUid) {
      this.connectFromUid.set(comp.uid);
      this.selectComponent.emit(comp);
      this.render();
      return;
    }
    if (fromUid === comp.uid) {
      this.connectFromUid.set(null);
      this.render();
      return;
    }

    const source = this.store.components().find((c) => c.uid === fromUid);
    const result = this.store.setFeedFrom(comp.uid, fromUid);
    this.connectFromUid.set(null);
    if (!result.ok) {
      this.connectionChange.emit(result.message ?? 'Връзката не можа да се създаде.');
      this.render();
      return;
    }
    this.selectComponent.emit(comp);
    this.connectionChange.emit(
      `Връзка: ${source?.label ?? 'източник'} → ${comp.label}`
    );
  }

  private setPreviewFromDrag(
    comp: PlacedComponent,
    target: { row: number; startModule: number }
  ): void {
    this.previewPositions.clear();
    const deltaRow = target.row - comp.row;
    const deltaModule = target.startModule - comp.startModule;
    if (comp.groupId) {
      for (const member of this.store.components().filter((c) => c.groupId === comp.groupId)) {
        this.previewPositions.set(member.uid, {
          row: member.row + deltaRow,
          startModule: member.startModule + deltaModule,
        });
      }
      return;
    }
    this.previewPositions.set(comp.uid, target);
  }

  private moduleFromPointer(x: number, y: number): { row: number; startModule: number } {
    const design = this.store.design();
    const relX = x - (PADDING + 10);
    const startModule = Math.max(
      0,
      Math.min(design.enclosure.modulesPerRow - 1, Math.round(relX / MODULE_PX))
    );
    let row = 0;
    let best = Infinity;
    for (let r = 0; r < design.enclosure.rows; r++) {
      const dist = Math.abs(this.rowY(r) + 10 - y);
      if (dist < best) {
        best = dist;
        row = r;
      }
    }
    return { row, startModule };
  }

  private showGhost(comp: PlacedComponent, target: { row: number; startModule: number }): void {
    if (!this.ghostLayer) return;
    this.ghostLayer.destroyChildren();
    const ok = this.store.canPlace(target.row, target.startModule, comp.widthModules, comp.uid) ||
      !!comp.groupId;
    this.ghostLayer.add(
      new Konva.Rect({
        x: PADDING + 10 + target.startModule * MODULE_PX,
        y: this.rowY(target.row) + 10,
        width: comp.widthModules * MODULE_PX,
        height: ROW_HEIGHT - 24,
        fill: ok ? 'rgba(46,125,50,0.18)' : 'rgba(198,40,40,0.18)',
        stroke: ok ? '#2e7d32' : '#c62828',
        dash: [4, 4],
        cornerRadius: 4,
      })
    );
    this.ghostLayer.draw();
  }
}
