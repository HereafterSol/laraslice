# LaraSlice UI Component Mapping

Maps YAML UI config to BlatUI components. AI porters and generators use this to select the right component.

## Directory (List Page)

| YAML Config | BlatUI Component | Usage |
|-------------|------------------|-------|
| `directory.columns` | `<x-ui.data-table>` | Main table, columns map to table columns |
| `directory.summary_cards[]` | `<x-ui.stat>` | KPI cards above table. `label`→label, `aggregate:sum`→value (formatted), `formatter:money`→$ prefix |
| `directory.quick_filters[]` | `<x-ui.filter-tabs>` | Status tabs. `handle`→param, `options_from:distinct:X`→tabs from unique values |
| `directory.sidebar_filters[]` | `<x-ui.server-table>` filters | `type:date_range`→date picker, `type:select`→dropdown |
| `directory.searchable[]` | `<x-ui.data-table searchable>` | Search input filters these fields |
| `directory.bulk_actions[]` | `<x-ui.data-table bulkAction>` | `delete`→bulk delete, `export`→bulk export |
| `directory.row_actions` | `<x-ui.data-table>` row actions | `placement:inline`→visible buttons, `placement:dropdown`→⋯ menu |

## Presentation (Create/Edit/View)

| YAML Config | BlatUI Component | Usage |
|-------------|------------------|-------|
| `presentation.create: drawer` | `<x-ui.drawer>` | Opens form in right-side drawer |
| `presentation.create: page` | Full page | Navigates to `.create` route |
| `presentation.create: modal` | `<x-ui.dialog>` | Opens form in centered modal |
| `presentation.edit: drawer` | `<x-ui.drawer>` | Same as create |
| `presentation.edit: page` | Full page | Navigates to `.edit` route |
| `presentation.view: drawer` | `<x-ui.drawer>` | Read-only in drawer |
| `presentation.view: page` | Full page | Navigates to `.show` route |

## Forms

| YAML Config | BlatUI Component | Usage |
|-------------|------------------|-------|
| `forms.sections[]` | `<x-ui.card>` per section | `title`→card header, `grid`→columns |
| Field `type: repeater` | `<x-ui.repeater>` | Line items (invoice rows, order items) |
| Field `type: select` | `<x-ui.select>` or `<x-ui.combobox>` | `is_searchable:true`→combobox |
| Field `type: date` | `<x-ui.date-picker>` | Date input |
| Field `type: textarea` | `<x-ui.textarea>` | Multi-line text |

## Charts & Visualization

| YAML Config | BlatUI Component | Usage |
|-------------|------------------|-------|
| `dashboard.widgets[type:chart]` | `<x-ui.chart>` | 70 chart types available |
| `summary_cards[].series` | `<x-ui.sparkline>` | Mini trend in stat card |

## Navigation

| YAML Config | Behavior |
|-------------|----------|
| `navigation.order` | Numeric sort (10, 20, 30...) |
| `navigation.permission` | Gate visibility by permission |
| `navigation.icon` | Lucide icon name |
| `navigation.group` | Sidebar section grouping |
