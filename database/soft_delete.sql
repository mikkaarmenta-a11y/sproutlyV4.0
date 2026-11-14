-- Run once in the Supabase SQL editor (after schema.sql and sessions.sql; safe to re-run).
-- Deleting a record in the app now sets deleted_at instead of removing the row.
-- To restore a record: update <table> set deleted_at = null where <id> = ...;

alter table farmer   add column if not exists deleted_at timestamptz;
alter table farm     add column if not exists deleted_at timestamptz;
alter table crop     add column if not exists deleted_at timestamptz;
alter table planting add column if not exists deleted_at timestamptz;
alter table harvest  add column if not exists deleted_at timestamptz;
alter table app_user add column if not exists deleted_at timestamptz;

-- Unique rules should only apply to records that are not deleted,
-- so a deleted username/crop/farm name can be used again.
alter table app_user drop constraint if exists app_user_username_key;
alter table app_user drop constraint if exists app_user_email_key;
alter table crop     drop constraint if exists crop_crop_name_variety_key;
alter table farm     drop constraint if exists farm_farmer_id_farm_name_key;

create unique index if not exists app_user_username_active on app_user (username) where deleted_at is null;
create unique index if not exists app_user_email_active    on app_user (email)    where deleted_at is null;
create unique index if not exists crop_name_variety_active on crop (crop_name, variety) where deleted_at is null;
create unique index if not exists farm_owner_name_active   on farm (farmer_id, farm_name) where deleted_at is null;
