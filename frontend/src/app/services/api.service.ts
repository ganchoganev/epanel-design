import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  ComponentGroup,
  EtiProduct,
  Paginated,
  SeriesResponse,
} from '../models/catalog.models';
import { Bom, Project, ProjectSummary } from '../models/project.models';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  private base = environment.apiUrl;

  getProducts(params: Record<string, string | number> = {}): Observable<Paginated<EtiProduct>> {
    const query = new URLSearchParams(
      Object.entries(params).map(([k, v]) => [k, String(v)])
    ).toString();
    return this.http.get<Paginated<EtiProduct>>(`${this.base}/products?${query}`);
  }

  getSeries(): Observable<SeriesResponse> {
    return this.http.get<SeriesResponse>(`${this.base}/products/series`);
  }

  getGroups(): Observable<ComponentGroup[]> {
    return this.http.get<ComponentGroup[]>(`${this.base}/groups`);
  }

  createGroup(payload: Partial<ComponentGroup>): Observable<ComponentGroup> {
    return this.http.post<ComponentGroup>(`${this.base}/groups`, payload);
  }

  deleteGroup(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/groups/${id}`);
  }

  listProjects(): Observable<ProjectSummary[]> {
    return this.http.get<ProjectSummary[]>(`${this.base}/projects`);
  }

  getProject(id: number): Observable<Project> {
    return this.http.get<Project>(`${this.base}/projects/${id}`);
  }

  createProject(payload: Partial<Project>): Observable<Project> {
    return this.http.post<Project>(`${this.base}/projects`, payload);
  }

  updateProject(id: number, payload: Partial<Project>): Observable<Project> {
    return this.http.put<Project>(`${this.base}/projects/${id}`, payload);
  }

  deleteProject(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/projects/${id}`);
  }

  duplicateProject(id: number): Observable<Project> {
    return this.http.post<Project>(`${this.base}/projects/${id}/duplicate`, {});
  }

  createVersion(id: number, note?: string): Observable<unknown> {
    return this.http.post(`${this.base}/projects/${id}/versions`, { note });
  }

  getBom(id: number): Observable<Bom> {
    return this.http.get<Bom>(`${this.base}/projects/${id}/bom`);
  }

  exportUrl(id: number, type: 'pdf' | 'csv' | 'excel'): string {
    return `${this.base}/projects/${id}/export/${type}`;
  }

  previewPrices(file: File, headerRow = 1): Observable<PricePreview> {
    const form = new FormData();
    form.append('file', file);
    form.append('header_row', String(headerRow));
    return this.http.post<PricePreview>(`${this.base}/prices/preview`, form);
  }

  importPrices(file: File, mapping: PriceMapping, headerRow = 1): Observable<PriceImportResult> {
    const form = new FormData();
    form.append('file', file);
    form.append('header_row', String(headerRow));
    form.append('column_mapping[catalog_number]', String(mapping.catalog_number));
    form.append('column_mapping[price]', String(mapping.price));
    if (mapping.name !== null && mapping.name !== undefined) {
      form.append('column_mapping[name]', String(mapping.name));
    }
    if (mapping.model !== null && mapping.model !== undefined) {
      form.append('column_mapping[model]', String(mapping.model));
    }
    if (mapping.currency !== null && mapping.currency !== undefined) {
      form.append('column_mapping[currency]', String(mapping.currency));
    }
    return this.http.post<PriceImportResult>(`${this.base}/prices/import`, form);
  }

  importEplan(file: File): Observable<unknown> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post(`${this.base}/catalog/import/eplan`, form);
  }

  previewScheduleOffer(files: File[], tiles: File[] = []): Observable<ScheduleOffer> {
    const form = new FormData();
    for (const file of files) {
      form.append('files[]', file);
    }
    for (const tile of tiles) {
      form.append('tiles[]', tile);
    }
    return this.http.post<ScheduleOffer>(`${this.base}/offers/from-schedule`, form);
  }

  downloadScheduleOffer(file: File): Observable<Blob> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post(`${this.base}/offers/from-schedule/xlsx`, form, { responseType: 'blob' });
  }

  downloadScheduleDraft(offer: ScheduleOffer): Observable<Blob> {
    return this.http.post(`${this.base}/offers/draft/xlsx`, {
      boards: offer.boards,
      note: offer.note,
    }, { responseType: 'blob' });
  }

  downloadExport(id: number, type: 'pdf' | 'csv' | 'excel'): Observable<Blob> {
    return this.http.get(`${this.base}/projects/${id}/export/${type}`, { responseType: 'blob' });
  }

  downloadCatalogFile(): Observable<Blob> {
    return this.http.get(`${this.base}/catalog/file`, { responseType: 'blob' });
  }

  importCatalogFile(file: File): Observable<{ imported: number; updated: number; skipped: number }> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ imported: number; updated: number; skipped: number }>(`${this.base}/catalog/file`, form);
  }

  downloadCodeTemplate(): Observable<Blob> {
    return this.http.get(`${this.base}/codes/template`, { responseType: 'blob' });
  }

  importCodeSupplement(file: File): Observable<{ imported: number; replaced: number; linked: number; skipped: number }> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ imported: number; replaced: number; linked: number; skipped: number }>(
      `${this.base}/codes/supplements`,
      form
    );
  }

  codeReplacements(): Observable<{ count: number; rows: CodeReplacement[] }> {
    return this.http.get<{ count: number; rows: CodeReplacement[] }>(`${this.base}/codes/replacements`);
  }

  importCodeReplacements(file: File): Observable<{ imported: number; skipped: number; count: number }> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ imported: number; skipped: number; count: number }>(
      `${this.base}/codes/replacements`,
      form
    );
  }

  productPhoto(code: string): Observable<Blob> {
    return this.http.get(`${this.base}/eticad/photos/${code}`, { responseType: 'blob' });
  }

  productFace(code: string): Observable<ProductFace> {
    return this.http.get<ProductFace>(`${this.base}/eticad/faces/${code}`);
  }

  enclosures(): Observable<{ enclosures: EnclosureOption[] }> {
    return this.http.get<{ enclosures: EnclosureOption[] }>(`${this.base}/eticad/enclosures`);
  }
}

export interface PricePreview {
  headers: string[];
  preview_rows: (string | null)[][];
  suggested_mapping: PriceMapping;
}

export interface PriceMapping {
  catalog_number: number | null;
  name: number | null;
  model: number | null;
  price: number | null;
  currency: number | null;
}

export interface ProductFace {
  svg: string;
  width_mm: number;
  height_mm: number;
  rails?: { x: number; y: number; width: number }[];
}

export interface EnclosureOption {
  catalog_number: string;
  name: string;
  rows: number;
  modules_per_row: number;
  mounting: string;
}

export interface ScheduleOfferLine {
  catalog_number: string;
  name: string;
  quantity: number;
  unit_price: number | null;
}

export interface SchedulePlacement {
  id: number;
  board: string;
  x: number;
  y: number;
  file?: string;
  device_type: string;
  rating: string;
  catalog_number: string | null;
  name: string | null;
  unit_price: number | null;
}

/** Result of reading a designer PDF into an ETI offer. */
export interface ScheduleOffer {
  boards: Array<{
    name: string;
    quantity: number;
    lines: ScheduleOfferLine[];
  }>;
  unmatched: Array<{
    board: string;
    rating: string;
    device_type: string;
    quantity: number;
    reason: string;
  }>;
  unread: Array<{
    board: string;
    text: string;
    quantity: number;
    reason: string;
    x?: number;
    y?: number;
    file?: string;
  }>;
  note: string;
  placements: SchedulePlacement[];
}

export interface PriceImportResult {
  updated: number;
  created: number;
  notFound: number;
  skipped: number;
}

export interface CodeReplacement {
  from_code: string;
  to_code: string;
}


