# Changelog

## 0.1.0-beta.1 (2026-09-04)

- Erste Version: Warn- und Alarmmeldungen für Deutschland über NINA-Aggregation
  (MoWaS/KATWARN/BIWAPP/DWD/Hochwasser/Polizei) und optionale direkte DWD-CAP-Anbindung.
- Geometrisches Umkreis-Matching (Polygon/Kreis der Meldung, keine Postleitzahlen-Näherung)
  gegen beliebig viele selbst definierte Standorte, je mit eigenem Radius und
  Mindest-Schweregrad.
- Automatische Push-Benachrichtigung an alle gefundenen WebFront-Instanzen (inkl. Handy),
  ohne manuelle Verknüpfung.
- Schutzaktionen: Raffstore/Rollladen hochfahren, Garagentor schließen, akustischer Alarm
  (mit Auto-Aus), eigenes Skript -- je nach Auslöse-Kategorie (Sturm/Hagel/Starkregen/
  Gewitter/Schnee/Hitze) und Mindest-Schweregrad, optional auf einzelne Standorte beschränkt.
- Öffentlicher Vertrag `WHUB_GetActiveWarnings()` für eigene Auswertungen/Kacheln.
