/** Conductors tracked by the wiring engine. */
export type Conductor = 'L1' | 'L2' | 'L3' | 'N' | 'PE';

export const CONDUCTOR_COLORS: Record<Conductor, string> = {
  L1: '#8b5a2b',
  L2: '#1f1f1f',
  L3: '#6b7280',
  N: '#2563eb',
  PE: '#16a34a',
};

export type DeviceRole = 'incoming' | 'distribution' | 'protection' | 'load' | 'auxiliary';

export interface PlacedComponent {
  uid: string;
  catalogNumber: string;
  name: string;
  label: string;
  series: string | null;
  category?: string | null;
  row: number;
  startModule: number;
  widthModules: number;
  groupId?: string;
  verified: boolean;
  /** Poles of the physical device, used for terminal geometry and phase load. */
  poles?: number;
  ratedCurrentA?: number | null;
  residualCurrentA?: number | null;
  tripCurve?: string | null;
  heatDissipationW?: number | null;
  /** Conductors this device is fed from, e.g. ['L1'] or ['L1','L2','L3','N']. */
  phases?: Conductor[];
  role?: DeviceRole;
  /** uid of the device feeding this one; null for the incoming device. */
  feedFromUid?: string | null;
  /** Designer hid the N drop from this device to the N bar. */
  omitNeutralDrop?: boolean;
  /** Designer hid the PE drop from this device to the PE bar. */
  omitPeDrop?: boolean;
}

export interface Connection {
  from: string;
  to: string;
  type: string;
}

/** One end of a wire: a specific pole on a specific side of a device. */
export interface Terminal {
  componentUid: string;
  side: 'top' | 'bottom';
  pole: number;
  conductor: Conductor;
}

export interface Wire {
  id: string;
  /** Sequential wire mark printed on the ferrule, e.g. "W014". */
  wireNumber: string;
  from: Terminal;
  to: Terminal;
  conductor: Conductor;
  crossSectionMm2: number;
  colorHex: string;
  /** Estimated routed length inside the enclosure, in millimetres. */
  lengthMm: number;
  viaBusbarId?: string;
  note?: string;
}

/** A physical comb busbar (IZS/IZ) spanning several adjacent modules. */
export interface BusbarRun {
  id: string;
  catalogNumber: string;
  name: string;
  row: number;
  startModule: number;
  spanModules: number;
  phases: 1 | 3;
  connectionType: 'PIN' | 'FORK';
  sourceUid: string;
  targetUids: string[];
  ratedCurrentA: number;
}

/** N and PE distribution bars mounted in the enclosure. */
export interface DistributionBar {
  id: string;
  catalogNumber: string;
  name: string;
  conductor: 'N' | 'PE';
  ways: number;
  usedWays: number;
}

/** An outgoing circuit: protective device plus the cable to the load. */
export interface Circuit {
  id: string;
  number: string;
  name: string;
  description?: string;
  protectiveDeviceUid: string;
  conductors: Conductor[];
  loadKw: number;
  powerFactor: number;
  /** Cable family, e.g. NYM-J. Combined with cores and mm² into cableType. */
  cableKind?: string;
  /** Display label, e.g. "NYM-J 3x1,5". Rebuilt when kind or section changes. */
  cableType: string;
  cableCrossSectionMm2: number;
  lengthM: number;
  /** Computed voltage drop over the cable at rated load, in percent. */
  voltageDropPercent?: number;
}

export interface WiringData {
  wires: Wire[];
  busbars: BusbarRun[];
  bars: DistributionBar[];
  circuits: Circuit[];
  incomingUid: string | null;
  generatedAt: string | null;
}

export interface DesignWarning {
  id: string;
  severity: 'error' | 'warning' | 'info';
  category: 'phase' | 'fill' | 'thermal' | 'selectivity' | 'wiring' | 'protection';
  message: string;
  componentUids?: string[];
}

export interface EnclosureConfig {
  catalogNumber: string;
  name: string;
  rows: number;
  modulesPerRow: number;
  /** Supply system, drives how many conductors the incoming device gets. */
  supplySystem?: 'TN-S' | 'TN-C-S' | 'TT';
  phases?: 1 | 3;
  ipRating?: string;
  /** Maximum power the enclosure can dissipate before overheating, in watts. */
  thermalLimitW?: number;
  ambientTempC?: number;
}

export interface LegendEntry {
  label: string;
  description: string;
}

export interface DesignData {
  enclosure: EnclosureConfig;
  rows: number[];
  components: PlacedComponent[];
  connections: Connection[];
  legend: LegendEntry[];
  manualItems: ManualItem[];
  wiring: WiringData;
  /**
   * Single-line diagram, rendered by the frontend and stored with the design so
   * the server-side PDF can embed it without duplicating the drawing rules.
   * Filled in when the project is saved.
   */
  schematicSvg?: string | null;
}

export function emptyWiring(): WiringData {
  return {
    wires: [],
    busbars: [],
    bars: [],
    circuits: [],
    incomingUid: null,
    generatedAt: null,
  };
}

export interface ManualItem {
  catalogNumber?: string;
  name: string;
  quantity: number;
  unitPrice?: number;
}

export interface Project {
  id: number;
  name: string;
  description: string | null;
  client_name: string | null;
  panel_config: EnclosureConfig | null;
  design_data: DesignData;
  current_version: number;
  is_template: boolean;
  updated_at?: string;
}

export interface ProjectSummary {
  id: number;
  name: string;
  client_name: string | null;
  is_template: boolean;
  current_version: number;
  updated_at: string;
}

export interface BomItem {
  catalog_number: string;
  name: string;
  series: string | null;
  quantity: number;
  unit: string;
  unit_price: number;
  line_total: number;
  currency: string;
  verified: boolean;
}

export interface Bom {
  items: BomItem[];
  totals: {
    subtotal: number;
    currency: string;
    item_count: number;
    component_count: number;
  };
  legend: LegendEntry[];
}

/** Visibility of drawing layers in the 2D editor, CAD-style. */
export type EditorLayerId =
  | 'enclosure'
  | 'devices'
  | 'cables'
  | 'comb'
  | 'bars'
  | 'labels';

export type EditorLayers = Record<EditorLayerId, boolean>;

export const DEFAULT_EDITOR_LAYERS: EditorLayers = {
  enclosure: true,
  devices: true,
  cables: true,
  comb: true,
  bars: true,
  labels: false,
};

export const EDITOR_LAYER_META: Array<{ id: EditorLayerId; label: string; hint: string }> = [
  { id: 'enclosure', label: 'Корпус', hint: 'Рамка и DIN шини' },
  { id: 'devices', label: 'Апаратура', hint: 'Автомати, ДТЗ, контактори' },
  { id: 'cables', label: 'Кабели', hint: 'L/N/PE връзки между апаратите' },
  { id: 'comb', label: 'Гребен', hint: 'Гребенни шини върху редовете' },
  { id: 'bars', label: 'N / PE', hint: 'Нулева и защитна шина' },
  { id: 'labels', label: '№ жила', hint: 'Номера и сечения на проводниците' },
];

/** Common outgoing cable families a panel designer actually specifies. */
export const CABLE_KINDS: Array<{ id: string; label: string; hint: string }> = [
  { id: 'NYM-J', label: 'NYM-J', hint: 'В стена / под мазилка' },
  { id: 'NYM-O', label: 'NYM-O', hint: 'Без PE жило' },
  { id: 'NYY-J', label: 'NYY-J', hint: 'В земя / открито полагане' },
  { id: 'NYCY', label: 'NYCY', hint: 'С концентрична жила' },
  { id: 'H05VV-F', label: 'H05VV-F', hint: 'Гъвкав, подвижни товари' },
  { id: 'H07RN-F', label: 'H07RN-F', hint: 'Гума, външен монтаж' },
  { id: 'NHXH-J', label: 'NHXH-J', hint: 'Пожарозащитен' },
  { id: 'N2XH-J', label: 'N2XH-J', hint: 'Безхалогенен' },
];

/** Standard copper cross-sections in mm². */
export const CABLE_SECTIONS_MM2 = [1.5, 2.5, 4, 6, 10, 16, 25, 35, 50];

export function cableCores(conductors: Conductor[]): number {
  return Math.max(conductors.length, 2);
}

export function formatMm2(mm2: number): string {
  return Number.isInteger(mm2) ? String(mm2) : String(mm2).replace('.', ',');
}

export function formatCableType(kind: string, conductors: Conductor[], mm2: number): string {
  return `${kind} ${cableCores(conductors)}x${formatMm2(mm2)}`;
}

export function parseCableKind(cableType: string | undefined, fallback = 'NYM-J'): string {
  if (!cableType) return fallback;
  const token = cableType.trim().split(/\s+/)[0];
  return token || fallback;
}

