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
}

export interface PricePreview {
  headers: string[];
  preview_rows: (string | null)[][];
  suggested_mapping: PriceMapping;
}

export interface PriceMapping {
  catalog_number: number | null;
  price: number | null;
  currency: number | null;
}

export interface PriceImportResult {
  updated: number;
  notFound: number;
  skipped: number;
}
