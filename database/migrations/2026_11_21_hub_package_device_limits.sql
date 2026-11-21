-- Hub workspace packages: device limits on the hub internet.
-- NULL = no limit. hubnet_ensure_schema() adds these columns automatically;
-- this file is for running by hand.
--   devices_per_person: devices each person (primary member or team member) may register
--   max_devices:        devices the whole company may register

ALTER TABLE hub_space_packages ADD COLUMN devices_per_person INT UNSIGNED NULL;
ALTER TABLE hub_space_packages ADD COLUMN max_devices INT UNSIGNED NULL;
