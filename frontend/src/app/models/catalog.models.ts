export interface EtiProduct {
  id: number;
  catalog_number: string;
  name: string;
  series: string | null;
  category: string | null;
  poles: number | null;
  rated_current_a: string | number | null;
  rated_voltage_v?: string | number | null;
  residual_current_a?: string | number | null;
  rcd_type?: string | null;
  trip_curve: string | null;
  breaking_capacity_ka: string | number | null;
  width_modules: number;
  busbar_modules?: number | null;
  eti_code?: string | null;
  product_url?: string | null;
  width_mm: string | number | null;
  height_mm: string | number | null;
  depth_mm: string | number | null;
  heat_dissipation_w: string | number | null;
  mounting_type: string | null;
  price: string | number | null;
  currency: string;
  data_source: string;
  verified: boolean;
  raw_attributes: Record<string, unknown> | null;
  compatible_accessories: unknown[] | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface SeriesResponse {
  series: string[];
  categories: string[];
}

export interface ComponentGroup {
  id: number;
  name: string;
  description: string | null;
  is_system: boolean;
  items: GroupItem[];
  connections: GroupConnection[] | null;
}

export interface GroupItem {
  catalog_number: string;
  label: string;
  offset_module: number;
}

export interface GroupConnection {
  from: string;
  to: string;
  type: string;
}
