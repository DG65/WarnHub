# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModule-blue)
![Module version](https://img.shields.io/badge/Module-1.20.0-informational)
![Symcon version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Warning and alert messages for Germany, Austria and Switzerland -- official sources for Germany
(civil protection, weather, floods, police, water levels, radioactivity), direct official
sources for Austria (GeoSphere Austria) and Switzerland (Alertswiss, BAFU flood danger levels,
earthquakes), Europe-wide weather warnings for 39 countries and optionally your own weather
station as an independent safety net -- filtered to the radius you define around your own
(also mobile) locations, with multi-channel push notifications to WebFront/tile visualization
devices, Telegram, Pushover, e-mail and Notification Control, plus optional protective actions.

The German [README.md](README.md) is the detailed reference; this English version summarises it.

## Language

Since 1.20.0 the configuration form, notifications, tiles, status lines and log messages follow
the language of your Symcon system (German or English). **The warnings themselves stay in the
language of their source** -- NINA, DWD and the other German and Swiss authorities publish in
German, Meteoalarm in English. WarnHub does not machine-translate official warnings.

## What does this module do?

WarnHub bundles official warnings from several sources that can be switched on individually:

- **NINA aggregation** (`warnung.bund.de`, active by default) -- the same API the official BBK
  warning app NINA uses: MoWaS civil protection warnings, KATWARN, BIWAPP, DWD weather warnings,
  flood warnings (LHP) and police messages.
- **Direct DWD weather warnings** (`opendata.dwd.de`) -- optional, more detailed recommended
  actions and more precise municipality polygons than the NINA summary.
- **PEGELONLINE** (`pegelonline.wsv.de`) -- optional, warns about water levels above the mean or
  previous highest water level at a nearby gauge.
- **BfS ambient dose rate** (`imis.bfs.de`) -- optional, warns when your own radioactivity
  threshold is exceeded; a form popup puts the value into context.
- **DWD forest fire danger index** and **UBA ozone** (Germany) -- optional, official scales,
  nearest measuring station per location, with a cache so that short outages of the source do
  not show up as errors.
- **Meteoalarm** (`feeds.meteoalarm.org`) -- optional, Europe-wide weather warnings for
  39 countries, important for mobile locations abroad. The feeds only name administrative areas,
  so matching is done by name and is marked as "name match" in the message. For Germany, NINA or
  the direct DWD connection take over automatically; for Austria, GeoSphere Austria does.
- **Alertswiss** (`alert.swiss`, BABS) -- optional, Swiss civil protection messages (cantonal
  fire bans, drought, rockfall, road closures) with real polygons. No weather warnings -- it
  complements Meteoalarm, it does not replace it.
- **GeoSphere Austria** (`warnungen.zamg.at`) -- optional, official Austrian warnings,
  coordinate-exact.
- **BAFU flood danger** (`lindas.admin.ch`) and **Swiss earthquakes** (SED, ETH Zurich) --
  optional.
- **Own weather station** (Froggit/Ecowitt, Sainlogic/ELV, Meteobridge or any wind/rain
  variable) -- triggers independently of official warnings when your own thresholds are
  exceeded.
- **VKF hail protection signal box** (Switzerland) -- optional, BETA, untested.

A warning only reaches a location if its warning area lies within the radius you set and it
reaches at least the selected severity. The radius check is geometric against the actual
warning area, not against postcodes.

## Installation

Install **WarnHub** from the Symcon Module Store, then create an instance of type **WarnHub**.

## Setup

1. Create at least one location: take it from the Symcon system settings, search an address
   (OpenStreetMap Nominatim), pick a point on the map, or search for vehicle/location
   variables for a mobile location (Tessie, Geofency, Stellantis, Smartcar, BMW ConnectedDrive,
   Hyundai/Kia Bluelink, OVMS native).
2. Check the data sources (NINA is active by default). They are grouped by country; the
   country of your Symcon system location opens automatically.
3. Click "Search push targets" (WebFront, tile visualization, Telegram bot, Pushover, SMTP,
   Notification Control). E-mail targets stay inactive until you enter a target address.
4. Optionally search the object tree for external blinds, awnings, garage doors, windows,
   tailgates and sirens. **Check the target value of every found row** before a real warning
   arrives -- which value means "open" or "raised" depends on the manufacturer.

Found items are suggested already activated; deselect what you do not want. A new search only
adds new items and keeps your decisions.

**Safety:** closing car windows or a tailgate automatically has no anti-trap protection. Only
activate these action types if nobody can be within reach of the vehicle unattended.

## Public functions

- `WHUB_Poll($id): string` -- run a check immediately, returns a readable summary.
- `WHUB_GetActiveWarnings($id): string` -- JSON list of the currently matching warnings per
  location (`standort`, `distanceKm`, `source`, ...).
- `WHUB_TestSchutzaktionen($id, $category): string` -- trigger all active protective actions of
  one alert type for testing (`sturm`, `hagel`, `starkregen`, `gewitter`, `schnee`, `hitze`,
  `rauch`).
- `WHUB_GetHistory($id, $limit = 100): string` -- JSON list of past warnings and all-clears.
- `WHUB_SnoozePush($id, $minutes): string` / `WHUB_CancelSnooze($id): string` -- pause push
  notifications; detection and protective actions keep running.
- `WHUB_TestPush($id): string` -- send a test notification to all active push targets.

## Tiles

Five variables contain ready-made HTML for the WebFront or tile visualization: compact,
overview (up to 8 cards), all warnings (scrollable, hide/filter per browser), map (one pin per
location) and the ZAMG warning map for Austria. Link them into your visualization via the
object tree.

## License

PolyForm Noncommercial 1.0.0 -- free for private and non-commercial use. Commercial use needs a
separate licence: dietmar@gureth.eu. See [LICENSE](LICENSE).
