import { Component, computed, inject } from '@angular/core';
import { DesignStore } from '../services/design-store.service';
import { SchematicService } from '../services/schematic.service';

/**
 * Renders the single-line diagram as inline SVG. SVG is used rather than canvas
 * so the drawing can be copied straight into the PDF pack and stays crisp at
 * any print scale.
 */
@Component({
  selector: 'app-single-line-diagram',
  standalone: true,
  template: `
    <div class="sld">
      <div class="sld__toolbar">
        <span class="sld__title">Еднолинейна схема</span>
        <span class="sld__meta">
          {{ diagram().circuitCount }} кръга · {{ diagram().wireCount }} проводника
        </span>
        <button type="button" class="sld__btn" (click)="download()">Изтегли SVG</button>
      </div>
      @if (!diagram().hasContent) {
        <p class="sld__empty">
          Добавете апарати и генерирайте окабеляване, за да се начертае схемата.
        </p>
      } @else {
        <div class="sld__canvas" [innerHTML]="diagram().safeSvg"></div>
      }
    </div>
  `,
  styles: [
    `
      .sld {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
      }
      .sld__toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        border-bottom: 1px solid #e0e0e0;
        background: #fafafa;
      }
      .sld__title {
        font-size: 13px;
        font-weight: 600;
        color: #263238;
      }
      .sld__meta {
        font-size: 11px;
        color: #78909c;
        margin-right: auto;
      }
      .sld__btn {
        font-size: 11px;
        padding: 4px 10px;
        border: 1px solid #b0bec5;
        border-radius: 4px;
        background: #fff;
        cursor: pointer;
      }
      .sld__btn:hover {
        background: #eceff1;
      }
      .sld__empty {
        margin: 24px;
        font-size: 12px;
        color: #90a4ae;
      }
      .sld__canvas {
        flex: 1;
        overflow: auto;
        padding: 12px;
        background: #fff;
        -webkit-overflow-scrolling: touch;
        touch-action: pan-x pan-y;
      }
      .sld__canvas :global(svg) {
        display: block;
      }
    `,
  ],
})
export class SingleLineDiagramComponent {
  private store = inject(DesignStore);
  private schematic = inject(SchematicService);

  readonly diagram = computed(() => {
    const design = this.store.design();
    const svg = this.schematic.buildSvg(design);
    return {
      safeSvg: svg,
      hasContent: design.components.length > 0,
      circuitCount: design.wiring.circuits.length,
      wireCount: design.wiring.wires.length,
    };
  });

  download(): void {
    const svg = this.schematic.buildSvg(this.store.design());
    const blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ednolinejna-shema.svg';
    a.click();
    URL.revokeObjectURL(url);
  }
}
