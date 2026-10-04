import { EtiProduct } from '../models/catalog.models';
import {
  DesignData,
  EnclosureConfig,
  PlacedComponent,
  emptyWiring,
} from '../models/project.models';
import { EnclosureOption, packDevices } from './board-fit';

export interface OfferBoardInput {
  name: string;
  lines: { catalog_number: string; name: string; quantity: number }[];
}

export interface BuiltBoard {
  name: string;
  design: DesignData;
  leftover: number;
}

function toNumber(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null;
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function enclosureConfig(option: EnclosureOption): EnclosureConfig {
  return {
    catalogNumber: option.catalog_number,
    name: option.name,
    rows: option.rows,
    modulesPerRow: option.modules_per_row,
    supplySystem: 'TN-C-S',
    phases: 3,
    ipRating: 'IP40',
    thermalLimitW: Math.round(20 + option.rows * option.modules_per_row * 1.4),
    ambientTempC: 30,
  };
}

/** The 2D arrangement, as a wiring layout: same enclosure, same rows, same modules. */
export function designFromOfferBoard(
  board: OfferBoardInput,
  option: EnclosureOption,
  modulesOf: (code: string) => number,
  productOf: (code: string) => EtiProduct | null,
): BuiltBoard {
  const packed = packDevices(
    board.lines,
    modulesOf,
    () => 90,
    option.rows,
    option.modules_per_row,
  );
  const components: PlacedComponent[] = packed.placed.map((device, index) => {
    const product = productOf(device.code);
    const poles = product?.poles ?? (device.modules >= 3 ? 3 : 1);
    return {
      uid: `offer-${index + 1}-${device.code}`,
      catalogNumber: device.code,
      name: product?.name || device.name || device.code,
      label: index === 0 ? 'QF' : `F${index}`,
      series: product?.series ?? null,
      category: product?.category ?? null,
      row: device.row,
      startModule: device.xMm / 18,
      widthModules: device.modules,
      verified: product?.verified ?? false,
      poles,
      ratedCurrentA: toNumber(product?.rated_current_a),
      residualCurrentA: toNumber(product?.residual_current_a),
      tripCurve: product?.trip_curve ?? null,
      heatDissipationW: toNumber(product?.heat_dissipation_w),
      role: index === 0 ? 'incoming' : 'protection',
    };
  });

  return {
    name: board.name,
    leftover: packed.leftover,
    design: {
      enclosure: enclosureConfig(option),
      rows: Array.from({ length: option.rows }, (_, index) => index),
      components,
      connections: [],
      legend: [],
      manualItems: [],
      wiring: emptyWiring(),
    },
  };
}
