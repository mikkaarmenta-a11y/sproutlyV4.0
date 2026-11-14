drop table if exists app_users, ref_roles, ref_irrigation, ref_crop_category, ref_season, ref_soil_type,
                     ref_planting_status, ref_quality_grade, ref_harvest_remarks,
                     harvest_remark, quality_grade, planting_status, irrigation_type,
                     soil_type, season, crop_category, location, user_role cascade;

do $$
declare t text;
begin
  foreach t in array array['harvest','planting','farm','crop','farmer'] loop
    if to_regclass('public.' || t) is not null then
      if exists (select 1 from information_schema.columns
                 where table_schema='public' and table_name=t and column_name='created_by') then
        execute format('drop table public.%I cascade', t);
      else
        execute format('alter table public.%I rename to %I', t, t || '_legacy');
      end if;
    end if;
  end loop;

  if to_regclass('public.app_user') is not null then
    if exists (select 1 from information_schema.columns
               where table_schema='public' and table_name='app_user' and column_name='role') then
      alter table app_user rename to app_user_legacy;
    else
      drop table app_user cascade;
    end if;
  end if;
end $$;

create table harvest_remark (
  remark_id int primary key,
  remark_description varchar(100) not null unique
);

insert into harvest_remark (remark_id, remark_description) values
  (1, 'Good Harvest'),
  (2, 'Acceptable Quality'),
  (3, 'Low Yield'),
  (4, 'Poor Quality'),
  (5, 'Damaged Harvest')
on conflict (remark_id) do update set remark_description = excluded.remark_description;

create table user_role       (role_id smallint generated always as identity primary key, role_name text not null unique);
create table crop_category   (category_id smallint generated always as identity primary key, category_name text not null unique);
create table season          (season_id smallint generated always as identity primary key, season_name text not null unique);
create table soil_type       (soil_type_id smallint generated always as identity primary key, soil_type_name text not null unique);
create table irrigation_type (irrigation_id smallint generated always as identity primary key, irrigation_name text not null unique);
create table planting_status (status_id smallint generated always as identity primary key, status_name text not null unique);
create table quality_grade   (grade_id smallint generated always as identity primary key, grade_name text not null unique);

create table location (
  location_id smallint generated always as identity primary key,
  city_municipality varchar(100) not null,
  province varchar(100) not null default 'Cebu',
  unique (city_municipality, province)
);

insert into user_role (role_name) values ('Administrator'),('Staff'),('Super Admin');
insert into crop_category (category_name) values ('Cereal'),('Vegetable'),('Fruit'),('Legume'),('Root Crop');
insert into season (season_name) values ('Wet Season'),('Dry Season'),('Year Round');
insert into soil_type (soil_type_name) values ('Loam'),('Clay Loam'),('Sandy Loam'),('Clay'),('Sandy'),('Silt Loam');
insert into irrigation_type (irrigation_name) values ('Rainfed'),('Irrigated'),('Drip Irrigation'),('Sprinkler');
insert into planting_status (status_name) values ('Planted'),('Growing'),('Ready for Harvest'),('Harvested'),('Failed');
insert into quality_grade (grade_name) values ('Grade A'),('Grade B'),('Grade C'),('Rejected');

insert into location (city_municipality, province) values
  ('Cebu City', 'Cebu'),
  ('Carcar City', 'Cebu'),
  ('Danao City', 'Cebu'),
  ('Argao', 'Cebu'),
  ('Bogo City', 'Cebu'),
  ('Toledo City', 'Cebu'),
  ('Naga City', 'Cebu'),
  ('Minglanilla', 'Cebu'),
  ('Liloan', 'Cebu'),
  ('Compostela', 'Cebu'),
  ('Talisay City', 'Cebu'),
  ('Consolacion', 'Cebu'),
  ('Dalaguete', 'Cebu'),
  ('Barili', 'Cebu'),
  ('Sibonga', 'Cebu');

create table app_user (
  user_id int generated always as identity primary key,
  username varchar(50) not null unique check (length(username) >= 3),
  full_name varchar(100) not null,
  email varchar(100) not null unique,
  role_id smallint not null references user_role(role_id),
  password_hash varchar(255) not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text
);

create table farmer (
  farmer_id int generated always as identity primary key,
  first_name varchar(100) not null,
  last_name varchar(100) not null,
  contact_number varchar(20) not null check (contact_number ~ '^[0-9+() -]{7,20}$'),
  street_address varchar(150),
  location_id smallint not null references location(location_id),
  date_registered date not null default current_date,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text
);

create table crop (
  crop_id int generated always as identity primary key,
  crop_name varchar(100) not null,
  variety varchar(100) not null,
  category_id smallint not null references crop_category(category_id),
  growing_period_days int not null check (growing_period_days between 1 and 730),
  season_id smallint not null references season(season_id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text,
  unique (crop_name, variety)
);

create table farm (
  farm_id int generated always as identity primary key,
  farmer_id int not null references farmer(farmer_id) on delete restrict,
  farm_name varchar(150) not null,
  location_id smallint not null references location(location_id),
  area_hectares numeric(6,2) not null check (area_hectares > 0),
  soil_type_id smallint not null references soil_type(soil_type_id),
  irrigation_id smallint not null references irrigation_type(irrigation_id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text,
  unique (farmer_id, farm_name)
);

create table planting (
  planting_id int generated always as identity (start with 11) primary key,
  farm_id int not null references farm(farm_id) on delete restrict,
  crop_id int not null references crop(crop_id) on delete restrict,
  planting_date date not null,
  area_planted numeric(6,2) not null check (area_planted > 0),
  seed_quantity numeric(8,2) not null check (seed_quantity > 0),
  expected_harvest_date date not null,
  status_id smallint not null references planting_status(status_id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text,
  constraint chk_planting_dates check (expected_harvest_date >= planting_date)
);

create table harvest (
  harvest_id int generated always as identity primary key,
  planting_id int not null references planting(planting_id) on delete restrict,
  harvest_date date not null,
  quantity_kg numeric(10,2) not null check (quantity_kg > 0),
  selling_price_per_kg numeric(8,2) not null check (selling_price_per_kg >= 0),
  grade_id smallint not null references quality_grade(grade_id),
  remark_id int not null references harvest_remark(remark_id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  created_by text,
  updated_by text
);

create index on farmer(location_id);
create index on farm(farmer_id);
create index on farm(location_id);
create index on planting(farm_id);
create index on planting(crop_id);
create index on harvest(planting_id);

do $$
begin
  if to_regclass('public.farmer_legacy') is not null
     and to_regclass('public.crop_legacy') is not null
     and to_regclass('public.farm_legacy') is not null
     and to_regclass('public.planting_legacy') is not null
     and to_regclass('public.harvest_legacy') is not null then

    insert into location (city_municipality, province)
      select distinct trim(split_part(l.address, ',', 1)), 'Cebu'
      from farmer_legacy l
      where l.address is not null and trim(split_part(l.address, ',', 1)) <> ''
        and not exists (select 1 from location r where lower(r.city_municipality)=lower(trim(split_part(l.address, ',', 1))));
    insert into location (city_municipality, province)
      select distinct trim(split_part(l.location, ',', 1)), 'Cebu'
      from farm_legacy l
      where l.location is not null and trim(split_part(l.location, ',', 1)) <> ''
        and not exists (select 1 from location r where lower(r.city_municipality)=lower(trim(split_part(l.location, ',', 1))));
    insert into crop_category (category_name) select distinct trim(l.crop_category) from crop_legacy l
      where l.crop_category is not null and not exists (select 1 from crop_category r where lower(r.category_name)=lower(trim(l.crop_category)));
    insert into season (season_name) select distinct trim(l.recommended_season) from crop_legacy l
      where l.recommended_season is not null and not exists (select 1 from season r where lower(r.season_name)=lower(trim(l.recommended_season)));
    insert into soil_type (soil_type_name) select distinct trim(l.soil_type) from farm_legacy l
      where l.soil_type is not null and not exists (select 1 from soil_type r where lower(r.soil_type_name)=lower(trim(l.soil_type)));
    insert into irrigation_type (irrigation_name) select distinct trim(l.irrigation_type) from farm_legacy l
      where l.irrigation_type is not null and not exists (select 1 from irrigation_type r where lower(r.irrigation_name)=lower(trim(l.irrigation_type)));
    insert into planting_status (status_name) select distinct trim(l.status) from planting_legacy l
      where l.status is not null and not exists (select 1 from planting_status r where lower(r.status_name)=lower(trim(l.status)));
    insert into quality_grade (grade_name) select distinct trim(l.quality_grade) from harvest_legacy l
      where l.quality_grade is not null and not exists (select 1 from quality_grade r where lower(r.grade_name)=lower(trim(l.quality_grade)));

    insert into farmer (farmer_id,first_name,last_name,contact_number,street_address,location_id,date_registered,created_by)
      overriding system value
      select l.farmer_id,l.first_name,l.last_name,l.contact_number,null,loc.location_id,coalesce(l.date_registered,current_date),'migration'
      from farmer_legacy l
      join location loc on lower(loc.city_municipality)=lower(trim(split_part(l.address, ',', 1)));

    insert into crop (crop_id,crop_name,variety,category_id,growing_period_days,season_id,created_by)
      overriding system value
      select l.crop_id,l.crop_name,l.variety,c.category_id,l.growing_period_days,s.season_id,'migration'
      from crop_legacy l
      join crop_category c on lower(c.category_name)=lower(trim(l.crop_category))
      join season s on lower(s.season_name)=lower(trim(l.recommended_season));

    insert into farm (farm_id,farmer_id,farm_name,location_id,area_hectares,soil_type_id,irrigation_id,created_by)
      overriding system value
      select l.farm_id,l.farmer_id,l.farm_name,loc.location_id,l.area_hectares,st.soil_type_id,i.irrigation_id,'migration'
      from farm_legacy l
      join location loc on lower(loc.city_municipality)=lower(trim(split_part(l.location, ',', 1)))
      join soil_type st on lower(st.soil_type_name)=lower(trim(l.soil_type))
      join irrigation_type i on lower(i.irrigation_name)=lower(trim(l.irrigation_type));

    insert into planting (planting_id,farm_id,crop_id,planting_date,area_planted,seed_quantity,expected_harvest_date,status_id,created_by)
      overriding system value
      select l.planting_id,l.farm_id,l.crop_id,l.planting_date,l.area_planted,l.seed_quantity,l.expected_harvest_date,s.status_id,'migration'
      from planting_legacy l
      join planting_status s on lower(s.status_name)=lower(trim(l.status));

    insert into harvest (harvest_id,planting_id,harvest_date,quantity_kg,selling_price_per_kg,grade_id,remark_id,created_by)
      overriding system value
      select l.harvest_id,l.planting_id,l.harvest_date,l.quantity_kg,l.selling_price_per_kg,g.grade_id,l.remark_id,'migration'
      from harvest_legacy l
      join quality_grade g on lower(g.grade_name)=lower(trim(l.quality_grade));

    perform setval(pg_get_serial_sequence('farmer','farmer_id'),     (select coalesce(max(farmer_id),0)+1   from farmer),   false);
    perform setval(pg_get_serial_sequence('crop','crop_id'),         (select coalesce(max(crop_id),0)+1     from crop),     false);
    perform setval(pg_get_serial_sequence('farm','farm_id'),         (select coalesce(max(farm_id),0)+1     from farm),     false);
    perform setval(pg_get_serial_sequence('planting','planting_id'), (select greatest(coalesce(max(planting_id),0)+1, 11) from planting), false);
    perform setval(pg_get_serial_sequence('harvest','harvest_id'),   (select coalesce(max(harvest_id),0)+1  from harvest),  false);
  end if;

  if not exists (select 1 from farmer) then
    insert into farmer (farmer_id,first_name,last_name,contact_number,street_address,location_id,date_registered,created_by)
    overriding system value
    select v.id,v.a,v.b,v.c,v.st,loc.location_id,v.e::date,'seed' from (values
    (1,'Juan','Dela Cruz','09171234567',null::varchar(150),'Cebu City','2026-10-26'),
    (2,'Maria','Santos','09181234567',null::varchar(150),'Carcar City','2026-10-26'),
    (3,'Pedro','Reyes','09191234567',null::varchar(150),'Danao City','2026-10-26'),
    (4,'Ana','Garcia','09201234567',null::varchar(150),'Argao','2026-10-26'),
    (5,'Jose','Mendoza','09211234567',null::varchar(150),'Bogo City','2026-10-26'),
    (6,'Ramon','Flores','09221234567',null::varchar(150),'Toledo City','2026-10-26'),
    (7,'Elena','Cruz','09231234567',null::varchar(150),'Naga City','2026-10-26'),
    (8,'Antonio','Lim','09241234567',null::varchar(150),'Minglanilla','2026-10-26'),
    (9,'Rosa','Fernandez','09251234567',null::varchar(150),'Liloan','2026-10-26'),
    (10,'Mario','Villanueva','09261234567',null::varchar(150),'Compostela','2026-10-26'),
    (11,'Teresa','Navarro','09271234567',null::varchar(150),'Talisay City','2026-10-26'),
    (12,'Carlos','Ramos','09281234567',null::varchar(150),'Consolacion','2026-10-26'),
    (13,'Liza','Torres','09291234567',null::varchar(150),'Dalaguete','2026-10-26'),
    (14,'Roberto','Castillo','09301234567',null::varchar(150),'Barili','2026-10-26'),
    (15,'Susan','Aquino','09311234567',null::varchar(150),'Sibonga','2026-10-26')
    ) as v(id,a,b,c,st,city,e)
    join location loc on loc.city_municipality=v.city;

    insert into crop (crop_id,crop_name,variety,category_id,growing_period_days,season_id,created_by)
    overriding system value
    select v.id,v.n,v.var,c.category_id,v.d,s.season_id,'seed' from (values
    (1,'Rice','NSIC Rc 222','Cereal',115,'Wet Season'),
    (2,'Corn','Yellow Corn','Cereal',100,'Dry Season'),
    (3,'Tomato','Diamante Max','Vegetable',90,'Dry Season'),
    (4,'Eggplant','Morena','Vegetable',100,'Year Round'),
    (5,'Banana','Lakatan','Fruit',300,'Year Round'),
    (6,'Mango','Carabao','Fruit',150,'Dry Season'),
    (7,'Peanut','BPI Pn 9','Legume',110,'Dry Season'),
    (8,'Cassava','Golden Yellow','Root Crop',240,'Year Round'),
    (9,'Sweet Potato','NSIC Sp 30','Root Crop',120,'Year Round'),
    (10,'Watermelon','Sweet 16','Fruit',85,'Dry Season')
    ) as v(id,n,var,cat,d,sea)
    join crop_category c on c.category_name=v.cat
    join season s on s.season_name=v.sea;

    insert into farm (farm_id,farmer_id,farm_name,location_id,area_hectares,soil_type_id,irrigation_id,created_by)
    overriding system value
    select v.id,v.fid,v.n,loc.location_id,v.a,st.soil_type_id,i.irrigation_id,'seed' from (values
    (1,1,'Dela Cruz Farm','Cebu City',2.5,'Loam','Rainfed'),
    (2,2,'Santos Rice Farm','Carcar City',4.2,'Clay Loam','Irrigated'),
    (3,3,'Reyes Agricultural Farm','Danao City',3.8,'Sandy Loam','Rainfed'),
    (4,4,'Garcia Vegetable Farm','Argao',1.75,'Loam','Drip Irrigation'),
    (5,5,'Mendoza Farm','Bogo City',5.0,'Clay','Irrigated'),
    (6,6,'Flores Corn Farm','Toledo City',3.4,'Sandy Loam','Rainfed'),
    (7,7,'Cruz Family Farm','Naga City',2.8,'Loam','Irrigated'),
    (8,8,'Lim Agricultural Farm','Minglanilla',2.25,'Clay Loam','Drip Irrigation'),
    (9,9,'Fernandez Farm','Liloan',3.1,'Sandy Loam','Rainfed'),
    (10,10,'Villanueva Green Farm','Compostela',4.5,'Loam','Irrigated'),
    (11,11,'Navarro Rice Field','Talisay City',3.75,'Clay','Irrigated'),
    (12,12,'Ramos Farm','Consolacion',2.6,'Loam','Rainfed'),
    (13,13,'Torres Vegetable Farm','Dalaguete',1.8,'Sandy Loam','Drip Irrigation'),
    (14,14,'Castillo Agricultural Farm','Barili',5.5,'Clay Loam','Irrigated'),
    (15,15,'Aquino Farm','Sibonga',3.2,'Loam','Rainfed')
    ) as v(id,fid,n,city,a,soil,irr)
    join location loc on loc.city_municipality=v.city
    join soil_type st on st.soil_type_name=v.soil
    join irrigation_type i on i.irrigation_name=v.irr;

    insert into planting (planting_id,farm_id,crop_id,planting_date,area_planted,seed_quantity,expected_harvest_date,status_id,created_by)
    overriding system value
    select v.id,v.fid,v.cid,v.pd::date,v.ap,v.sq,v.ed::date,s.status_id,'seed' from (values
    (11,11,1,'2026-02-09',0.50,20.00,'2026-06-04','Harvested'),
    (12,12,2,'2026-02-12',0.60,25.00,'2026-05-23','Harvested'),
    (13,13,3,'2026-02-15',0.70,30.00,'2026-05-16','Harvested'),
    (14,14,4,'2026-02-18',0.80,35.00,'2026-05-29','Harvested'),
    (15,15,5,'2026-02-21',0.90,40.00,'2026-12-18','Harvested'),
    (16,1,6,'2026-02-24',1.00,45.00,'2026-07-24','Harvested'),
    (17,2,7,'2026-02-27',1.10,10.00,'2026-06-17','Harvested'),
    (18,3,8,'2026-03-02',1.20,15.00,'2026-10-28','Harvested'),
    (19,4,9,'2026-03-05',1.30,20.00,'2026-07-03','Harvested'),
    (20,5,10,'2026-03-08',1.40,25.00,'2026-06-01','Harvested'),
    (21,6,1,'2026-03-11',0.50,30.00,'2026-07-04','Growing'),
    (22,7,2,'2026-03-14',0.60,35.00,'2026-06-22','Growing'),
    (23,8,3,'2026-03-17',0.70,40.00,'2026-06-15','Growing'),
    (24,9,4,'2026-03-20',0.80,45.00,'2026-06-28','Growing'),
    (25,10,5,'2026-03-23',0.90,10.00,'2027-01-17','Growing'),
    (26,11,6,'2026-03-26',1.00,15.00,'2026-08-23','Growing'),
    (27,12,7,'2026-03-29',1.10,20.00,'2026-07-17','Growing'),
    (28,13,8,'2026-04-01',1.20,25.00,'2026-11-27','Growing')
    ) as v(id,fid,cid,pd,ap,sq,ed,stt)
    join planting_status s on s.status_name=v.stt;

    insert into harvest (harvest_id,planting_id,harvest_date,quantity_kg,selling_price_per_kg,grade_id,remark_id,created_by)
    overriding system value
    select v.id,v.pid,v.hd::date,v.q,v.p,g.grade_id,v.rid,'seed' from (values
    (1,11,'2026-05-07',900.00,22.00,'Grade A',1),
    (2,12,'2026-04-25',1025.00,25.50,'Grade B',2),
    (3,13,'2026-04-18',1150.00,29.00,'Grade C',3),
    (4,14,'2026-05-01',1275.00,32.50,'Grade A',1),
    (5,15,'2026-11-20',1400.00,36.00,'Grade B',2),
    (6,16,'2026-06-26',1525.00,39.50,'Grade C',3),
    (7,17,'2026-05-20',1650.00,43.00,'Grade A',1),
    (8,18,'2026-09-30',1775.00,46.50,'Grade B',2),
    (9,19,'2026-06-05',1900.00,22.00,'Grade C',3),
    (10,20,'2026-05-04',2025.00,25.50,'Grade A',1),
    (11,11,'2026-06-06',2150.00,29.00,'Grade B',2),
    (12,12,'2026-05-25',2275.00,32.50,'Grade C',3),
    (13,13,'2026-05-18',2400.00,36.00,'Grade A',1),
    (14,14,'2026-05-31',2525.00,39.50,'Grade B',2),
    (15,15,'2026-12-20',2650.00,43.00,'Grade C',3),
    (16,16,'2026-07-26',2775.00,46.50,'Grade A',1),
    (17,17,'2026-06-19',2900.00,22.00,'Grade B',2),
    (18,18,'2026-10-30',3025.00,25.50,'Grade C',3),
    (19,19,'2026-07-05',3150.00,29.00,'Grade A',1),
    (20,20,'2026-06-03',3275.00,32.50,'Grade B',2)
    ) as v(id,pid,hd,q,p,gr,rid)
    join quality_grade g on g.grade_name=v.gr;

    perform setval(pg_get_serial_sequence('farmer','farmer_id'),     (select max(farmer_id)+1   from farmer),   false);
    perform setval(pg_get_serial_sequence('crop','crop_id'),         (select max(crop_id)+1     from crop),     false);
    perform setval(pg_get_serial_sequence('farm','farm_id'),         (select max(farm_id)+1     from farm),     false);
    perform setval(pg_get_serial_sequence('planting','planting_id'), (select max(planting_id)+1 from planting), false);
    perform setval(pg_get_serial_sequence('harvest','harvest_id'),   (select max(harvest_id)+1  from harvest),  false);
  end if;
end $$;

create or replace function set_updated_at() returns trigger as $$
begin new.updated_at = now(); return new; end;
$$ language plpgsql;

do $$
declare t text;
begin
  foreach t in array array['app_user','farmer','crop','farm','planting','harvest'] loop
    execute format('create trigger trg_%1$s_updated before update on %1$s
                    for each row execute function set_updated_at()', t);
  end loop;
end $$;

do $$
declare r record;
begin
  for r in select policyname, tablename from pg_policies where schemaname='public' loop
    execute format('drop policy %I on public.%I', r.policyname, r.tablename);
  end loop;
  for r in select tablename from pg_tables where schemaname='public' loop
    execute format('alter table public.%I enable row level security', r.tablename);
  end loop;
end $$;

revoke all on all tables in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;
grant usage on schema public to service_role;
grant all privileges on all tables in schema public to service_role;
grant all privileges on all sequences in schema public to service_role;
alter default privileges in schema public grant all on tables to service_role;
alter default privileges in schema public grant all on sequences to service_role;

create extension if not exists pgcrypto with schema extensions;

insert into app_user (username, full_name, email, role_id, password_hash, created_by) values
 ('admin','Mikka Armenta','mikka.armenta@gmail.com',3,'$2y$10$Ba/ntXU7wQrYb5oadTqfcOhh4vMlw7R2Man9U2G2MOrVDeHClpsc6','seed'),
 ('staff','Ariana Grande','agrande6767@gmail.com',2,'$2y$10$CKos3MBeIxiIFZ64ccWrGuVNZa3NTNo2g6pWFJPIsjrADhRisT0tO','seed'),
 ('matty','Matty Healy','matty.healy@gmail.com',1, extensions.crypt('the1975', extensions.gen_salt('bf', 10)),'seed');