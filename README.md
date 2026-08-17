# ETI Panel Designer

Браузър-базиран софтуер за проектиране на електрически табла само с апаратура на ETI ([etigroup.eu](https://www.etigroup.eu/)). Помага на проектанта да разположи апаратите в табло, да ги свърже в предварително дефинирани групи, да получи количествена сметка и да генерира проектна документация (PDF/Excel/CSV).

Това е Фаза 1 (ядро) от плана. Инженерният конфигуратор, схемите и AI функциите са следващи фази.

## Стек

- **Frontend:** Angular 19 (standalone, signals) + Konva.js за 2D редактора; PWA (service worker + web manifest).
- **Backend:** Laravel 13 (PHP 8.3+), REST API под `/api/v1`.
- **База данни:** MySQL (production) / SQLite (dev по подразбиране).
- **Export:** dompdf (PDF), PhpSpreadsheet / maatwebsite-excel (Excel).

Архитектурата държи 2D и 3D изгледите като различни "камери" към един и същ JSON модел на проекта, така че бъдещ 3D изглед с Three.js е добавяне на изглед, не пренаписване.

## Структура

```
eti_panel_designer/
  frontend/            # Angular SPA (PWA), Konva 2D редактор
  backend/             # Laravel API
  docker/              # nginx + Dockerfile-и
  docker-compose.yml   # production
  docker-compose.dev.yml
```

## Локална разработка (без Docker)

Backend:

```bash
cd backend
composer install
php artisan migrate --seed        # SQLite по подразбиране
php artisan serve --port=8010
```

Frontend:

```bash
cd frontend
npm install
npm start                          # http://localhost:4200
```

Frontend dev сочи към `http://localhost:8010/api/v1` (виж `src/environments/environment.ts`). При production build се ползва `environment.prod.ts` с относителен `/api/v1`.

## С Docker

Production (nginx сервира Angular build-а и проксира `/api` към PHP-FPM, MySQL с volume):

```bash
docker compose up -d --build       # приложението е на http://localhost:8080
```

Dev (hot reload на Angular + `php artisan serve`):

```bash
docker compose -f docker-compose.dev.yml up
```

## Данни за каталога

- **Технически данни:** официалната ETI EPLAN библиотека (EDS, XML). Импорт през UI (таб „Каталог“ → „Импорт на EPLAN XML“) или `POST /api/v1/catalog/import/eplan`. Парсерът е толерантен към различни имена на полета.
- **Цени:** отделен Excel от дистрибутора през wizard-а в таб „Цени“ — качва се файл, посочва се коя колона е каталожен номер / цена / валута, мапингът може да се запази като профил.
- Началният `EtiCatalogSeeder` зарежда примерен набор ETI серии (ETIMAT, EFI, KZS, ETIBREAK, ETITEC, ETICON, ETISWITCH, ETIBOX) с флаг `verified=false`, докато не се качат официалните данни.

## API (кратко)

| Метод | Път | Описание |
|-------|-----|----------|
| GET | `/api/v1/products` | Каталог с филтри (search, series, poles, ток) |
| GET | `/api/v1/products/series` | Списък серии/категории |
| POST | `/api/v1/catalog/import/eplan` | Импорт на EPLAN XML |
| POST | `/api/v1/prices/preview` | Преглед на Excel преди импорт |
| POST | `/api/v1/prices/import` | Импорт на цени с мапинг |
| GET/POST | `/api/v1/groups` | Предварително свързани групи |
| GET/POST/PUT/DELETE | `/api/v1/projects` | Проекти (CRUD) |
| POST | `/api/v1/projects/{id}/duplicate` | Дублиране |
| POST | `/api/v1/projects/{id}/versions` | Нова версия |
| GET | `/api/v1/projects/{id}/bom` | Количествена сметка |
| GET | `/api/v1/projects/{id}/export/{pdf\|excel\|csv}` | Експорт |

## Възможности (Фаза 1)

- Каталог само с ETI апаратура, с търсене и филтри; флаг „потвърдено/изчислено“.
- Визуален редактор: избор на ETIBOX корпус, автоматични DIN редове, drag & drop с прилепване към модулите и проверка за застъпване.
- Предварително свързани групи (ДТЗ + автомати, вход + отводител) — местят се като едно цяло, връзките ги следват.
- Автоматично етикетиране (F1, FI1, Q1…), undo/redo, автоматично подреждане.
- Количествена сметка в реално време + експорт PDF/Excel/CSV.
- Проекти: запис, версии, дублиране, изтриване.
- PWA — инсталируемо и работи офлайн на десктоп и мобилен браузър (вкл. iPhone Safari).

## Ограничения

Инструментът е помощен за проектанта. Не удостоверява безопасност или съответствие със стандарти (Icc, селективност, пад на напрежение, загряване, координация на защитите). Изисква проверка от правоспособен проектант.
