-- Vani dashboard support tables.
-- Run this in the Supabase SQL editor.
-- The current PHP app uses the publishable/anon key, so these policies allow
-- anon/authenticated access. Tighten them later when per-user JWT auth is added.

create table if not exists public.chatbot_settings (
  id bigserial primary key,
  customer_id uuid not null unique references public.chatbot_signups(customer_id) on delete cascade,
  bot_name text,
  welcome_message text default 'Hi, how can I help you today?',
  theme_color text default '#6366f1',
  position text default 'right' check (position in ('left', 'right')),
  avatar_url text,
  language text default 'English',
  is_active boolean not null default true,
  api_key text,
  rate_limit integer not null default 100,
  notification_preference text default 'weekly_summary',
  allowed_domains text,
  verification_status text default 'Pending',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.chatbot_conversations (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  user_question text not null,
  bot_response text not null,
  matched_faq_id bigint,
  status text not null default 'unanswered' check (status in ('answered', 'unanswered')),
  is_answered boolean not null default false,
  user_id text,
  source_url text,
  created_at timestamptz not null default now()
);

create table if not exists public.customer_profiles (
  id bigserial primary key,
  email text not null unique,
  first_name text,
  last_name text,
  avatar_url text,
  country_code text,
  mobile_number text,
  address_line1 text,
  address_line2 text,
  city text,
  state_region text,
  country text,
  postal_code text,
  location_notes text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.lead_generation_settings (
  id bigserial primary key,
  customer_id uuid not null unique references public.chatbot_signups(customer_id) on delete cascade,
  is_enabled boolean not null default false,
  collect_location boolean not null default false,
  verify_email_otp boolean not null default false,
  notify_lead_by_email boolean not null default false,
  notification_email text,
  redirect_whatsapp boolean not null default false,
  whatsapp_mobile_number text,
  verify_mobile_otp boolean not null default false,
  service_tier text not null default 'free' check (service_tier in ('free', 'paid')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint lead_generation_settings_notification_email_check
    check (notification_email is null or notification_email ~* '^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$'),
  constraint lead_generation_settings_whatsapp_mobile_check
    check (whatsapp_mobile_number is null or whatsapp_mobile_number ~ '^\+?[1-9][0-9]{7,14}$')
);

create table if not exists public.lead_generation_leads (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  conversation_id bigint references public.chatbot_conversations(id) on delete set null,
  name text,
  email text,
  phone_number text,
  location_text text,
  latitude numeric(10, 7),
  longitude numeric(10, 7),
  source_url text,
  whatsapp_redirected boolean not null default false,
  email_otp_verified boolean not null default false,
  mobile_otp_verified boolean not null default false,
  notification_email_sent boolean not null default false,
  verification_quality text not null default 'poor' check (verification_quality in ('poor', 'real')),
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);

alter table public.faq_questions
  add column if not exists category text default 'General';

alter table public.faq_questions enable row level security;

create index if not exists idx_chatbot_settings_customer_id
  on public.chatbot_settings(customer_id);

create index if not exists idx_chatbot_conversations_customer_id_created_at
  on public.chatbot_conversations(customer_id, created_at desc);

create index if not exists idx_chatbot_conversations_status
  on public.chatbot_conversations(status);

create index if not exists idx_faq_questions_customer_category
  on public.faq_questions(customer_id, category);

create index if not exists idx_customer_profiles_email
  on public.customer_profiles(email);

create index if not exists idx_lead_generation_settings_customer_id
  on public.lead_generation_settings(customer_id);

create index if not exists idx_lead_generation_leads_customer_created_at
  on public.lead_generation_leads(customer_id, created_at desc);

create index if not exists idx_lead_generation_leads_email
  on public.lead_generation_leads(email);

create index if not exists idx_lead_generation_leads_phone_number
  on public.lead_generation_leads(phone_number);

create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists set_chatbot_settings_updated_at on public.chatbot_settings;
create trigger set_chatbot_settings_updated_at
before update on public.chatbot_settings
for each row
execute function public.set_updated_at();

drop trigger if exists set_customer_profiles_updated_at on public.customer_profiles;
create trigger set_customer_profiles_updated_at
before update on public.customer_profiles
for each row
execute function public.set_updated_at();

drop trigger if exists set_lead_generation_settings_updated_at on public.lead_generation_settings;
create trigger set_lead_generation_settings_updated_at
before update on public.lead_generation_settings
for each row
execute function public.set_updated_at();

alter table public.chatbot_settings enable row level security;
alter table public.chatbot_conversations enable row level security;
alter table public.customer_profiles enable row level security;
alter table public.lead_generation_settings enable row level security;
alter table public.lead_generation_leads enable row level security;

drop policy if exists "dashboard settings readable" on public.chatbot_settings;
create policy "dashboard settings readable"
on public.chatbot_settings
for select
to anon, authenticated
using (true);

drop policy if exists "dashboard settings insertable" on public.chatbot_settings;
create policy "dashboard settings insertable"
on public.chatbot_settings
for insert
to anon, authenticated
with check (true);

drop policy if exists "dashboard settings updatable" on public.chatbot_settings;
create policy "dashboard settings updatable"
on public.chatbot_settings
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "dashboard conversations readable" on public.chatbot_conversations;
create policy "dashboard conversations readable"
on public.chatbot_conversations
for select
to anon, authenticated
using (true);

drop policy if exists "dashboard conversations insertable" on public.chatbot_conversations;
create policy "dashboard conversations insertable"
on public.chatbot_conversations
for insert
to anon, authenticated
with check (true);

drop policy if exists "customer profiles readable" on public.customer_profiles;
create policy "customer profiles readable"
on public.customer_profiles
for select
to anon, authenticated
using (true);

drop policy if exists "customer profiles insertable" on public.customer_profiles;
create policy "customer profiles insertable"
on public.customer_profiles
for insert
to anon, authenticated
with check (true);

drop policy if exists "customer profiles updatable" on public.customer_profiles;
create policy "customer profiles updatable"
on public.customer_profiles
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "lead generation settings readable" on public.lead_generation_settings;
create policy "lead generation settings readable"
on public.lead_generation_settings
for select
to anon, authenticated
using (true);

drop policy if exists "lead generation settings insertable" on public.lead_generation_settings;
create policy "lead generation settings insertable"
on public.lead_generation_settings
for insert
to anon, authenticated
with check (true);

drop policy if exists "lead generation settings updatable" on public.lead_generation_settings;
create policy "lead generation settings updatable"
on public.lead_generation_settings
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "lead generation leads readable" on public.lead_generation_leads;
create policy "lead generation leads readable"
on public.lead_generation_leads
for select
to anon, authenticated
using (true);

drop policy if exists "lead generation leads insertable" on public.lead_generation_leads;
create policy "lead generation leads insertable"
on public.lead_generation_leads
for insert
to anon, authenticated
with check (true);

drop policy if exists "lead generation leads updatable" on public.lead_generation_leads;
create policy "lead generation leads updatable"
on public.lead_generation_leads
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "lead generation leads deletable" on public.lead_generation_leads;
create policy "lead generation leads deletable"
on public.lead_generation_leads
for delete
to anon, authenticated
using (true);

drop policy if exists "customers password reset from dashboard" on public.customers;
create policy "customers password reset from dashboard"
on public.customers
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "faq questions readable" on public.faq_questions;
create policy "faq questions readable"
on public.faq_questions
for select
to anon, authenticated
using (true);

drop policy if exists "faq questions insertable" on public.faq_questions;
create policy "faq questions insertable"
on public.faq_questions
for insert
to anon, authenticated
with check (true);

drop policy if exists "faq questions updatable" on public.faq_questions;
create policy "faq questions updatable"
on public.faq_questions
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "faq questions deletable" on public.faq_questions;
create policy "faq questions deletable"
on public.faq_questions
for delete
to anon, authenticated
using (true);

grant select, insert, update, delete on public.chatbot_settings to anon, authenticated;
grant select, insert, update, delete on public.chatbot_conversations to anon, authenticated;
grant select, insert, update, delete on public.customer_profiles to anon, authenticated;
grant select, insert, update, delete on public.faq_questions to anon, authenticated;
grant select, insert, update, delete on public.lead_generation_settings to anon, authenticated;
grant select, insert, update, delete on public.lead_generation_leads to anon, authenticated;
grant update(password) on public.customers to anon, authenticated;
grant usage, select on sequence public.chatbot_settings_id_seq to anon, authenticated;
grant usage, select on sequence public.chatbot_conversations_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_profiles_id_seq to anon, authenticated;
grant usage, select on sequence public.lead_generation_settings_id_seq to anon, authenticated;
grant usage, select on sequence public.lead_generation_leads_id_seq to anon, authenticated;
