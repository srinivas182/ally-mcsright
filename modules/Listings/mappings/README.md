# Per-MLS field mappings

`{source key}.php` returns `[column => RESO field name | callable(array $record): mixed]`, applied after the
standard RESO Data Dictionary mapping (`ResoMapper`). Add one only when an MLS differs from the standard, with a
contract test using a recorded sample payload.
