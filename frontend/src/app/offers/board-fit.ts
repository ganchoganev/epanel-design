export interface EnclosureOption {
  catalog_number: string;
  name: string;
  rows: number;
  modules_per_row: number;
  mounting: string;
}

export interface FaceRail {
  x: number;
  y: number;
  width: number;
}

const CHOICE_KEY = 'eti_enclosure_choice';

export function loadEnclosureChoices(): Record<string, string> {
  try {
    const raw = sessionStorage.getItem(CHOICE_KEY);
    if (!raw) return {};
    const parsed = JSON.parse(raw) as Record<string, string>;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

export function saveEnclosureChoice(board: string, code: string): void {
  const all = loadEnclosureChoices();
  all[board] = code;
  sessionStorage.setItem(CHOICE_KEY, JSON.stringify(all));
}

/** Face width includes 1 mm of padding on each side. A module is 18 mm. */
export function deviceModules(widthMm: number): number {
  return Math.max(1, Math.round((widthMm - 2) / 18));
}

export function suggestEnclosure(
  options: EnclosureOption[],
  totalModules: number,
  widest: number,
): EnclosureOption | null {
  if (!options.length) return null;
  const fitting = options.filter(
    (option) => option.modules_per_row >= widest && option.rows * option.modules_per_row >= totalModules,
  );
  if (fitting.length) return sortBoards(fitting)[0];
  return [...options].sort((a, b) => b.rows * b.modules_per_row - a.rows * a.modules_per_row)[0];
}

function sortBoards(options: EnclosureOption[]): EnclosureOption[] {
  return [...options].sort((a, b) => {
    const capacity = a.rows * a.modules_per_row - b.rows * b.modules_per_row;
    if (capacity !== 0) return capacity;
    if (a.mounting !== b.mounting) return a.mounting === 'вграден' ? -1 : 1;
    if (a.rows !== b.rows) return a.rows - b.rows;
    return a.modules_per_row - b.modules_per_row;
  });
}

export interface PackedDevice {
  code: string;
  name: string;
  modules: number;
  heightMm: number;
  row: number;
  xMm: number;
}

export function packDevices(
  lines: { catalog_number: string; name: string; quantity: number }[],
  modulesOf: (code: string) => number,
  heightOf: (code: string) => number,
  rows: number,
  modulesPerRow: number,
): { placed: PackedDevice[]; leftover: number } {
  const placed: PackedDevice[] = [];
  let row = 0;
  let used = 0;
  let leftover = 0;
  for (const line of lines) {
    const modules = modulesOf(line.catalog_number);
    const heightMm = heightOf(line.catalog_number);
    for (let copy = 0; copy < line.quantity; copy++) {
      if (used > 0 && used + modules > modulesPerRow) {
        row += 1;
        used = 0;
      }
      if (row >= rows || modules > modulesPerRow) {
        leftover += modules;
        continue;
      }
      placed.push({
        code: line.catalog_number,
        name: line.name,
        modules,
        heightMm,
        row,
        xMm: used * 18,
      });
      used += modules;
    }
  }
  return { placed, leftover };
}

/** Detected DIN rows, or evenly spaced rows when the drawing has none. */
export function railsOrFallback(
  rails: FaceRail[],
  rows: number,
  modulesPerRow: number,
  widthMm: number,
  heightMm: number,
): FaceRail[] {
  if (rails.length === rows) return rails;
  const railWidth = modulesPerRow * 18;
  const x = Math.max(0, (widthMm - railWidth) / 2);
  const pitch = heightMm / (rows + 1);
  return Array.from({ length: rows }, (_, index) => ({
    x,
    y: pitch * (index + 1),
    width: railWidth,
  }));
}
