# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModule-blue)
![Module version](https://img.shields.io/badge/Module-1.20.1-informational)
![Symcon version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Warning and alert messages for Germany, Austria and Switzerland -- official sources for Germany
(civil protection, weather, floods, police, water levels, radioactivity), direct official
sources for Austria (GeoSphere Austria) and Switzerland (Alertswiss, BAFU flood danger levels),
Europe-wide weather warnings for 39 countries and optionally your own weather station as an
independent safety net -- filtered to the radius you define around your own (also mobile)
locations, with multi-channel push notifications to WebFront/tile visualization devices,
Telegram, Pushover, e-mail and Notification Control, plus optional protective actions.

Deutsche Fassung: [README.md](README.md).

## Language

Since 1.20.0 the configuration form, notifications, tiles, status lines and log messages follow
the language of your Symcon system (German or English). Since 1.20.1 this also applies to the
messages WarnHub generates itself (water levels, radioactivity, forest fire danger, ozone,
earthquakes, BAFU floods, own weather station, hail signal box). **Official warnings stay in the
language of their source** -- NINA, DWD, GeoSphere Austria and Alertswiss publish in German,
Meteoalarm in English. WarnHub does not machine-translate official warnings.

## What does this module do?

WarnHub bundles official warnings from several sources that can be switched on individually:

- **NINA aggregation** (`warnung.bund.de`, active by default) -- the same API the official BBK
  warning app NINA uses. Bundles MoWaS civil protection warnings, KATWARN, BIWAPP, DWD weather
  warnings, flood warnings (LHP) and police messages.
- **Direct DWD weather warnings** (`opendata.dwd.de`) -- optional, more detailed recommended
  actions and more precise municipality polygons than the NINA summary.
- **PEGELONLINE** (`pegelonline.wsv.de`) -- optional, warns about water levels above the mean or
  previous highest water level at a nearby gauge on a federal waterway.
- **BfS ambient dose rate** (`imis.bfs.de`) -- optional, warns when your own radioactivity
  threshold is exceeded. A popup in the form puts the value into context (dose rate and the
  calculated exposure time until the annual precautionary value for the public is reached).
- **Meteoalarm** (`feeds.meteoalarm.org`) -- optional, Europe-wide weather warnings for
  39 countries **including Austria and Switzerland**, important for mobile locations abroad.
  The freely available feeds provide no warning area, only named administrative areas -- so
  matching is done by name in a separate step that is clearly marked in the message, not
  geometrically. For Germany, NINA or the direct DWD connection take over automatically as soon
  as one of them is active (the Meteoalarm Germany feed would otherwise deliver the same DWD
  warnings a second time in English) -- analogous to GeoSphere Austria. For Switzerland,
  Meteoalarm remains the weather source even when Alertswiss is active (Alertswiss does not
  carry MeteoSwiss weather warnings). The alert category (storm, rain, thunderstorm, snow,
  heat) comes from Meteoalarm's standardised warning type.
- **Alertswiss** (`alert.swiss`, Federal Office for Civil Protection BABS, Switzerland) --
  optional, the Swiss counterpart to NINA: cantonal fire bans, drought, rockfall/landslides,
  road closures and other civil protection messages with real polygon/circle geometry. No
  weather warnings -- it complements Meteoalarm, it does not replace it.
- **GeoSphere Austria** (`warnungen.zamg.at`) -- optional, official warnings directly from the
  Austrian weather service (ZAMG), coordinate-exact instead of a name match. Takes over Austrian
  locations from Meteoalarm automatically once activated.
- **BAFU flood data** (`lindas.admin.ch`, Switzerland) -- optional, the official 5-level danger
  scale of the Federal Office for the Environment for rivers and lakes. Unlike
  PEGELONLINE/BfS/own weather station, a real official classification -- only the level from
  which WarnHub reports is adjustable.
- **Swiss earthquakes** (Swiss Seismological Service SED, ETH Zurich, `eida.ethz.ch`) --
  optional, official seismological monitoring, from magnitude 2.5 (the perceptibility threshold
  according to SED). Not a weather source -- SED provides neither a warning area nor a "valid
  until", an earthquake is an instantaneous event. WarnHub therefore draws its own circle around
  the epicentre (radius graded by magnitude: 15/30/60/120 km -- a rough approximation of its
  own, NOT an official hazard area) and a 2-hour window (relevant for aftershocks), after which
  the event no longer counts as active. Switzerland only.
- **German forest fire danger index** (DWD, `opendata.dwd.de`) -- optional, official 5-level
  scale (1 = very low to 5 = very high danger). There is no single file for "today, all
  stations", so WarnHub determines the nearest of the 484 DWD measuring stations for each German
  location and only queries its current value. The station list and the daily values are
  cached, so short outages of the source do not show up as errors. Requires the PHP extension
  `zlib` (for gzip, usually standard); without it only this source stays inactive. The DWD only
  calculates the index during the fire season (roughly March to September/October); a station
  value older than 3 days counts as outdated.
- **German ozone pollution** (Federal Environment Agency, `luftdaten.umweltbundesamt.de`) --
  optional, official air quality index, explicitly ONLY ozone/summer smog (no general air quality
  monitoring of other pollutants and no private indoor sensors -- WarnHub is not meant for that).
  Official 5-level scale (0 = very good to 4 = very poor), calculated from the hourly ozone
  concentration per station. Station list and readings are cached.
- **Own weather station** -- optional, independent of all other sources: triggers as soon as the
  locally measured wind gust or rain rate exceeds your own threshold. Both have three thresholds
  (Moderate/Severe/Extreme -- wind default 40/65/90 km/h, rain default 15/25/40 mm/h, both based
  on the DWD's own warning levels) -- an awning is more sensitive to wind than an external blind,
  so each protective action row chooses via its own "From severity" field from which level it
  reacts (the popups "Which threshold should I choose?" help). Also sends a real "✅ All-clear"
  push as soon as wind gust/rain rate are below the threshold again. A safety net in case
  official warnings do not report an event that actually occurs locally, or not in time. An
  object tree search finds a Froggit instance (Ecowitt protocol, also covers Ecowitt hardware
  sold as Sainlogic/HP1000SE/WH3000SE, including newer gateways with a piezo rain sensor such as
  the WS90), a Sainlogic/ELV instance (Wolbolar/IPSymconWeatherStation, Wunderground protocol) or
  a Meteobridge/Meteohub instance (data logger aggregator, also covers other brands such as
  DAVIS) automatically -- wind speeds are normalised correctly regardless of the source profile
  (km/h or m/s). If none of the three modules is found, a system-wide search for the matching
  Symcon standard profile serves as a last fallback. For any other make (KNX without an assigned
  profile, Netatmo, TFA, Homematic, ...) wind and rain variables can be selected manually, also
  mixed. Optionally (off by default) WarnHub resets an external blind/awning/garage door action
  triggered by your own weather station once wind AND rain have been continuously below the
  Moderate threshold for 20 minutes -- the only exception to "no automatic reset" in the whole
  module, because only your own weather station provides a continuous local live value. Before
  resetting it checks whether the state was changed by hand in the meantime and does not
  overwrite it in that case.
- **VKF hail protection signal box** (Switzerland, `meteo.netitservices.com`) -- **BETA,
  untested**: optional, integrates a hail protection signal box physically registered at a
  specific Swiss building (hagelschutz-einfach-automatisch.ch). Built from the official VKF
  documentation and the source code of an active community adapter, but not verified live for
  lack of a signal box of my own -- feedback welcome.

PEGELONLINE, BfS ODL-Info and your own weather station have no official warning level
classification -- WarnHub reports your own threshold instead, explicitly marked as such.

Each location (your home, second home, relatives) gets its own radius and minimum severity. A
warning only triggers if the location lies **geometrically** within the actual warning area
(polygon/circle of the message) or its radius -- not based on rough postcode or municipal
boundaries. Instead of fixed coordinates, a location can also be bound to two live variables
(lat/lon, e.g. from Tessie, Geofency or a Stellantis, Smartcar, BMW ConnectedDrive, Hyundai/Kia
Bluelink or OVMS native vehicle) -- WarnHub then reads the current position at every check. An
object tree search finds matching vehicle/location variable pairs automatically (in German and
English variable names) and creates directly linked, activated locations. A "Push only to" name
filter lets a location notify only certain push targets (e.g. one person/vehicle each when
several locations are used at the same time).

Active warnings appear as push notifications on all **activated** push targets -- WebFront and
tile visualization instances as well as, if installed, Telegram bot (official Symcon module),
Pushover (community module), SMTP instances (official Symcon module, for e-mail) and Notification
Control (devices registered only via IPSView), each found automatically in the object tree and
preselected; individual targets can be deselected. A found SMTP instance only knows the sending
route, not the recipient -- it is therefore created inactive until a target address is entered.
Active warnings can optionally trigger **protective actions** -- e.g. raise external blinds/roller
shutters, retract the awning, close the garage door, close car windows, set any variable to a
value (e.g. ventilation off when smoke gases are reported), close the boot/tailgate (with a
mandatory safety check against accidental opening, see below), switch an acoustic signal or run
your own script. Each protective action fires only once per warning; there is deliberately no
automatic reset -- that remains up to the user. A protective action without its own location
filter only fires for fixed locations, not for mobile ones. It also does not fire as soon as the
message arrives, but only shortly before it actually becomes valid (adjustable lead time,
default 30 minutes) -- a warning that arrives in the morning but is only valid for the afternoon
does not retract the awning in the morning. The push notification itself is not affected and
still arrives immediately.

Some hazards cannot be closed automatically -- above all a simple window or door, for which there
is no generic actuator. But if an opening contact exists (panel "Window/door monitoring"), WarnHub
detects that it is open and warns specifically when a matching warning is active -- instead of
closing, it reports. The object tree search finds contacts both via a classic Symcon system
profile (`~Window`/`~Door`, also `.Reversed`) and via the variable presentation introduced in
Symcon 9.0 with an "Opened"/"Open" (German "Geöffnet"/"Offen") option -- independent of the
manufacturer, Matter contacts included. A push is sent only once per warning and contact.

If an already reported warning escalates (e.g. the DWD raises it from Moderate to Severe), a new
push notification is sent -- a downgrade is not, to avoid unnecessary alarm. The push text also
contains the source's recommended action if available (CAP field `instruction`). Notifications
can be paused for a while ("quiet period", 1/4/24 hours -- e.g. holiday, party, night):
detection, warning history and protective actions keep running unchanged, only the delivery
itself is silent; a manual test click still arrives. A warning that has already expired no
longer counts as active, even if the source keeps delivering it late or incorrectly.

## Installation

Install **WarnHub** from the Symcon Module Store (or add `https://github.com/DG65/WarnHub` as a
library in the module management), then create an instance of type **WarnHub**.

## Setup

1. Create at least one location -- several ways are available: the button "Take location from
   Symcon system settings" (reads the core instance "Location"), address/postcode search via
   OpenStreetMap Nominatim, picking a point on the map, or the button "Search vehicle/location
   variables" for a mobile location (Tessie/Geofency/Stellantis/Smartcar/BMW ConnectedDrive/
   Hyundai-Kia Bluelink/OVMS native).
2. Check the data sources (NINA is active by default, all others optional) -- grouped by country
   (General/🇩🇪/🇦🇹/🇨🇭), the country of your Symcon system location opens automatically. For your
   own weather station the button "Search weather station" is usually enough.
3. Push notification: click "Search push targets" (finds WebFront and tile visualization
   instances and -- if installed -- Telegram bot, Pushover, SMTP and Notification Control
   instances).
4. Optionally click "Search object tree for external blinds/blinds/awnings/garage/close
   windows/tailgate/siren". External blind controls are also detected via Symcon's built-in
   shutter variable presentation, even if their name gives no hint.

### Auto-detection: found = activated, deselection possible

The location, data source, push target and protective action searches follow the same principle:
matches are suggested as **already activated** rows (a found mobile location is immediately
linked to its live variables, a found weather station is immediately ready, push goes to every
found target -- **except e-mail**, see above -- and every found external blind/awning/garage/
window/siren control is armed from the next matching warning) -- simply deselect unwanted
matches in the active column. A new search only adds new matches and leaves your active/inactive
decisions untouched. For protective actions, be sure to check the **target value** of every
found row before a real warning arrives -- which value means "open"/"raised" depends on the
manufacturer and is not guessed. Exception boot/tailgate: these rows stay **inactive** without an
automatically found state variable (safety lock, see "Limitations" below).

## Public functions

- `WHUB_Poll($id): string` -- triggers an immediate check, returns a readable summary.
- `WHUB_GetActiveWarnings($id): string` -- JSON list of the currently matching warnings per
  location (for your own evaluations/tiles), including `standort`, `distanceKm`, `source` and
  `category`.
- `WHUB_TestSchutzaktionen($id, $category): string` -- immediately triggers all active protective
  actions of the given alert type for testing (`sturm` storm, `hagel` hail, `starkregen` heavy
  rain, `gewitter` thunderstorm, `schnee` snow, `hitze` heat, `rauch` smoke), independent of a
  real warning, the location filter and the minimum severity -- a pure actuator test. Available
  in the form as one button per alert type.
- `WHUB_GetHistory($id, $limit = 100): string` -- JSON list of the most recently pushed warnings
  AND all-clears (newest first, up to 500 entries stored).
- `WHUB_SnoozePush($id, $minutes): string` -- pauses push notifications for the given duration.
  Detection, warning history and protective actions keep running unchanged. `WHUB_TestPush()` is
  exempt. Available in the form as buttons (1/4/24 h).
- `WHUB_CancelSnooze($id): string` -- ends a running push pause early.
- `WHUB_TestPush($id): string` -- sends a test notification to all activated push targets.

The alert type keys (`sturm`, `hagel`, ...) are identifiers and stay German in every language.

## IPSView & your own dashboards

WarnHub creates four status variables of its own (active warnings, highest severity, status,
last check) -- a starting point for your own dashboard, e.g. with IPSView. IPSView has no push
channel of its own but builds views exclusively from existing Symcon object IDs -- no separate
setup in WarnHub is needed, the variables are automatically in the instance's object tree.
Existing variables keep their names when the system language changes; only newly created ones
get names in the current language.

## Ready-made WebFront tiles

Five more variables contain finished, self-contained HTML -- nothing to build yourself, simply
link them into the WebFront area in the object tree:

- **Tile (compact)** -- a badge with a coloured circle (signal colour by the highest active
  severity, green = no active warning), count and "checked at" time. For a small tile grid.
- **Tile (overview)** -- list of the currently active warnings as cards (icon, event, location,
  distance for matches within the radius, end of validity), up to 8 at a time (sorted by
  severity, most important first), with a "+N more" hint above that.
- **Tile (all warnings)** -- like "Tile (overview)", but WITHOUT the cap of 8: scrollable list of
  ALL active warnings, also sorted by severity. Each card has its own "✕" button to hide it, plus
  a filter bar with one chip per event type currently present -- both remembered only in the
  respective browser (localStorage), no feedback channel to Symcon. Affects only this display --
  push, warning history and protective actions keep running server-side on the full list. Each
  card can be expanded with a click (recommended action, validity period, full official
  description); optionally all cards start expanded.
- **Tile (map)** -- map section (Esri street map) with EVERY active location as its own coloured
  pin (mobile locations too, following their live position; colour per location by its own
  highest active severity) plus a clickable legend below. The start view shows all locations
  together; a click on a pin/legend entry zooms to it -- EACH BROWSER remembers the focused
  location FOR ITSELF. Height automatic by default (fitted to the surrounding tile) -- this does
  not work reliably in every configuration, so a fixed number of pixels can be entered in the
  "Check & status" panel instead.
- **Tile (ZAMG warning map, Austria)** -- embeds the official ZAMG warning map
  (warnungen.zamg.at) directly. Currently shows all of Austria, not yet automatically centred on
  a single location.

"Tile (compact)", "Tile (overview)" and "Tile (all warnings)" use a modern translucent "Liquid
Glass" style, adapt to light/dark via `prefers-color-scheme` and are completely self-contained
HTML/CSS without external dependencies. All three also contain small links to the official
warning maps of the countries in which your locations lie.

"Tile (map)" and "Tile (ZAMG warning map)" deliberately load external resources (Leaflet.js from
unpkg.com plus Esri map tiles, or the embedded ZAMG page). Embedding the DWD and MeteoSwiss
warning maps was technically impossible -- both send `X-Frame-Options: SAMEORIGIN`. The map tiles
deliberately do NOT come from the OpenStreetMap tile servers, whose usage policy requires a
referer header for embedded third-party widgets like this tile (field report: HTTP 403 in
Firefox) -- but from Esri's free, referer-free map service (attribution "Tiles © Esri" is shown).

All tiles are updated automatically after every check, without any setup.

## Limitations

- If a message has no geometry (rare, mostly older/administrative message types), it is
  deliberately **not** assigned automatically -- no guessed precision.
- The direct DWD connection requires the PHP extensions `curl` and `ZipArchive`. Without them NINA
  still delivers DWD weather warnings, only without the extra detail. How to install PHP
  extensions depends on the operating system of your Symcon installation -- see the official
  installation guides on symcon.de. On a SymBox or a Catan controller this is not necessary.
- Meteoalarm provides no warning area, only named administrative areas -- matching is done by
  name (the location is assigned to a district/region via reverse geocoding), not geometrically.
  Less precise than the other sources, explicitly marked as "name match" in the message.
- Close boot/tailgate **only** triggers if an additional state variable confirms that the
  tailgate is currently open -- the underlying Tesla command is a pure toggle without direction,
  triggering it blindly could open an already closed tailgate. Without a valid state variable it
  does not trigger at all. The front trunk (frunk) can generally not be closed via the Tesla API,
  only opened, and is therefore not automated by WarnHub.
- The automatic weather station search recognises three modules: Froggit, Wolbolar/
  IPSymconWeatherStation for Sainlogic/ELV and Meteobridge/Meteohub -- all three verified against
  their source code, but not live for lack of a test device. If none of them is found, a
  system-wide search for the Symcon standard profile ("~WindSpeed.kmh"/"~WindSpeed.ms"/
  "~Rainfall") follows as a last fallback -- only taken over if there is EXACTLY one match. For
  any other module/make without a recognisable profile there is no automatic detection; select
  the wind and rain variables manually instead. Wind speeds are always normalised (m/s is
  converted to km/h based on the variable profile) -- a variable without any profile is assumed
  to be km/h already.
- Protective actions generally wait until shortly before a warning becomes valid -- messages
  without any time information (rare) still trigger immediately.
- GeoSphere Austria checks server-side whether a warning applies at the queried coordinate --
  WarnHub does not know the underlying municipality area and places the result as a small circle
  exactly at that coordinate. A location right at a municipal boundary can therefore just miss a
  warning in the neighbouring municipality a few hundred metres away.
- BAFU flood data only covers rivers and lakes of national interest with their own measuring
  station (around 180 stations in Switzerland).
- The radius around an earthquake epicentre and the 2-hour activity window are a rough
  approximation of WarnHub's own -- SED provides neither a hazard area nor a "valid until".
- The forest fire danger index refers to the NEAREST measuring station, not the location itself.
- Ozone pollution only reports stations that actually measure ozone (live check: around a
  quarter of the more than 1,000 UBA stations).
- The VKF hail protection connection is **BETA and untested** (see above).
- Telegram and Pushover push are **not verified live** for lack of my own bot/account -- the
  functions called (`TB_SendMessage` and `TUPO_SendMessage`) come directly from the real source
  code of the respective established Symcon modules. An already configured TelegramBot or
  Pushover instance is required. Telegram has no separate title in the function used, so title
  and text are combined into one message. The sound setting applies only to WebFront/tile
  visualization.
- E-mail push (SMTP): `SMTP_SendMailEx` was verified live against a real Symcon instance
  (function signature, module GUID), but not with a test send of my own. An already configured
  SMTP instance is required. The title becomes the subject, the text the HTML body.
- Window/door monitoring: only recognises a contact if it either carries a classic Symcon system
  profile (`~Window`/`~Door`, also `.Reversed`) or offers an option with the caption
  "Opened"/"Open"/"Closed" (German "Geöffnet"/"Offen"/"Geschlossen") via the variable
  presentation introduced in Symcon 9.0 -- without either, the meaning of true/false cannot be
  determined reliably and WarnHub does not guess. Which raw value means "open" is **not a fixed
  convention** (a classic `~Window` profile says `true` = open, many Matter-based contacts say
  `true` = closed), so the detection always reads the actual mapping.

## License

PolyForm Noncommercial 1.0.0 -- private/non-commercial use is free, commercial use requires a
separate licence from the rights holder (DG65). Full text: [LICENSE](LICENSE). Donations are
welcome: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).

## Acknowledgement

Dedicated to Sepp Lausch, who is working on his own Symcon warning connection at the same time --
the friendly rivalry has clearly done WarnHub good. 😉
