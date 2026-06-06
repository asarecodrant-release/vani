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
  theme_pattern text default 'none',
  position text default 'right' check (position in ('left', 'right')),
  avatar_url text,
  language text default 'English',
  is_active boolean not null default true,
  chat_open_by_default boolean not null default false,
  user_input_enabled boolean not null default true,
  api_key text,
  rate_limit integer not null default 100,
  notification_preference text default 'weekly_summary',
  website_verification_enabled boolean not null default false,
  allowed_domains_enabled boolean not null default false,
  allowed_domains text,
  webhook_url text,
  webhook_secret text,
  handoff_enabled boolean not null default false,
  handoff_email text,
  live_chat_actions_enabled boolean not null default false,
  faq_actions_enabled boolean not null default false,
  faq_category_menu_enabled boolean not null default false,
  faq_feedback_enabled boolean not null default false,
  faq_feedback_type text not null default 'labels',
  faq_feedback_action_ids jsonb not null default '[]'::jsonb,
  faq_feedback_email_enabled boolean not null default false,
  default_faq_settings jsonb not null default '{}'::jsonb,
  verification_status text default 'Pending',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.chatbot_settings
  add column if not exists chat_open_by_default boolean not null default false;

alter table public.chatbot_settings
  add column if not exists user_input_enabled boolean not null default true;

alter table public.chatbot_settings
  add column if not exists website_verification_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists allowed_domains_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists webhook_url text;

alter table public.chatbot_settings
  add column if not exists webhook_secret text;

alter table public.chatbot_settings
  add column if not exists handoff_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists handoff_email text;

alter table public.chatbot_settings
  add column if not exists live_chat_actions_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists faq_actions_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists faq_category_menu_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists faq_feedback_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists faq_feedback_type text not null default 'labels';

alter table public.chatbot_settings
  add column if not exists faq_feedback_action_ids jsonb not null default '[]'::jsonb;

alter table public.chatbot_settings
  add column if not exists faq_feedback_email_enabled boolean not null default false;

alter table public.chatbot_settings
  add column if not exists default_faq_settings jsonb not null default '{}'::jsonb;

alter table public.chatbot_settings
  add column if not exists theme_pattern text default 'none';

create table if not exists public.chatbot_conversations (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  user_question text not null,
  bot_response text not null,
  matched_faq_id bigint,
  status text not null default 'unanswered' check (status in ('answered', 'unanswered')),
  is_answered boolean not null default false,
  user_id text,
  session_id text,
  source_url text,
  referrer_url text,
  device_type text,
  browser_name text,
  browser_version text,
  os_name text,
  country_code text,
  country_name text,
  city text,
  timezone text,
  locale text,
  screen_width integer,
  screen_height integer,
  response_time_ms integer,
  created_at timestamptz not null default now()
);

alter table public.chatbot_conversations
  add column if not exists session_id text,
  add column if not exists referrer_url text,
  add column if not exists device_type text,
  add column if not exists browser_name text,
  add column if not exists browser_version text,
  add column if not exists os_name text,
  add column if not exists country_code text,
  add column if not exists country_name text,
  add column if not exists city text,
  add column if not exists timezone text,
  add column if not exists locale text,
  add column if not exists screen_width integer,
  add column if not exists screen_height integer,
  add column if not exists response_time_ms integer;

create table if not exists public.faq_action_suggestions (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  faq_id bigint not null references public.faq_questions(id) on delete cascade,
  label text not null,
  action_type text not null default 'link' check (action_type in ('link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category', 'payment')),
  action_value text,
  is_active boolean not null default true,
  display_order integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.faq_scheduled_action_suggestions (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  slot_no integer not null check (slot_no between 1 and 3),
  trigger_after_questions integer not null default 3 check (trigger_after_questions between 1 and 50),
  label text not null,
  action_type text not null default 'link' check (action_type in ('link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category', 'payment')),
  action_value text,
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint faq_scheduled_action_suggestions_customer_slot_unique unique (customer_id, slot_no)
);

create table if not exists public.faq_action_feedback (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  faq_id bigint references public.faq_questions(id) on delete set null,
  action_id bigint references public.faq_action_suggestions(id) on delete set null,
  action_type text,
  feedback_value text not null,
  user_id text,
  session_id text,
  source_url text,
  created_at timestamptz not null default now()
);

alter table public.faq_scheduled_action_suggestions
  drop constraint if exists faq_scheduled_action_suggestions_action_type_check;

alter table public.faq_scheduled_action_suggestions
  add constraint faq_scheduled_action_suggestions_action_type_check
  check (action_type in ('link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category', 'payment'));

alter table public.faq_action_suggestions
  drop constraint if exists faq_action_suggestions_action_type_check;

alter table public.faq_action_suggestions
  add constraint faq_action_suggestions_action_type_check
  check (action_type in ('link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category', 'payment'));

create table if not exists public.customer_payment_settings (
  id bigserial primary key,
  customer_id uuid not null unique references public.chatbot_signups(customer_id) on delete cascade,
  is_enabled boolean not null default false,
  razorpay_enabled boolean not null default false,
  razorpay_terms_accepted boolean not null default false,
  razorpay_terms_accepted_at timestamptz,
  upi_enabled boolean not null default false,
  upi_transaction_id_required boolean not null default true,
  upi_terms_accepted boolean not null default false,
  upi_terms_accepted_at timestamptz,
  provider text not null default 'razorpay',
  business_name text,
  collect_payer_email boolean not null default true,
  collect_payer_phone boolean not null default true,
  verify_payer_email_otp boolean not null default false,
  verify_payer_phone_otp boolean not null default false,
  razorpay_notify_status_email boolean not null default false,
  razorpay_notify_status_mobile boolean not null default false,
  razorpay_key_id text,
  razorpay_key_secret text,
  success_message text default 'Payment received. Thank you.',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.customer_payment_settings
  add column if not exists razorpay_enabled boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists razorpay_terms_accepted boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists razorpay_terms_accepted_at timestamptz;

alter table public.customer_payment_settings
  add column if not exists collect_payer_email boolean not null default true;

alter table public.customer_payment_settings
  add column if not exists collect_payer_phone boolean not null default true;

alter table public.customer_payment_settings
  add column if not exists verify_payer_email_otp boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists verify_payer_phone_otp boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists razorpay_notify_status_email boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists razorpay_notify_status_mobile boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists upi_enabled boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists upi_transaction_id_required boolean not null default true;

alter table public.customer_payment_settings
  add column if not exists upi_terms_accepted boolean not null default false;

alter table public.customer_payment_settings
  add column if not exists upi_terms_accepted_at timestamptz;

create table if not exists public.customer_payment_actions (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  payment_method text not null default 'razorpay' check (payment_method in ('razorpay', 'upi')),
  label text not null,
  description text,
  amount_paise integer not null check (amount_paise > 0),
  currency text not null default 'INR',
  upi_id text,
  upi_payee_name text,
  upi_note text,
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.customer_payment_actions
  add column if not exists payment_method text not null default 'razorpay';

alter table public.customer_payment_actions
  add column if not exists upi_id text;

alter table public.customer_payment_actions
  add column if not exists upi_payee_name text;

alter table public.customer_payment_actions
  add column if not exists upi_note text;

alter table public.customer_payment_actions
  drop constraint if exists customer_payment_actions_payment_method_check;

alter table public.customer_payment_actions
  add constraint customer_payment_actions_payment_method_check
  check (payment_method in ('razorpay', 'upi'));

create table if not exists public.customer_payment_transactions (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  payment_action_id bigint references public.customer_payment_actions(id) on delete set null,
  faq_action_id bigint references public.faq_action_suggestions(id) on delete set null,
  faq_id bigint references public.faq_questions(id) on delete set null,
  user_id text,
  session_id text,
  source_url text,
  payer_name text,
  payer_email text,
  payer_phone text,
  amount_paise integer not null,
  currency text not null default 'INR',
  status text not null default 'created' check (status in ('created', 'paid', 'failed')),
  payment_method text not null default 'razorpay' check (payment_method in ('razorpay', 'upi')),
  razorpay_order_id text unique,
  razorpay_payment_id text,
  razorpay_signature text,
  metadata jsonb not null default '{}'::jsonb,
  paid_at timestamptz,
  created_at timestamptz not null default now()
);

alter table public.customer_payment_transactions
  add column if not exists payment_method text not null default 'razorpay';

alter table public.customer_payment_transactions
  drop constraint if exists customer_payment_transactions_payment_method_check;

alter table public.customer_payment_transactions
  add constraint customer_payment_transactions_payment_method_check
  check (payment_method in ('razorpay', 'upi'));

alter table public.customer_payment_transactions
  drop constraint if exists customer_payment_transactions_status_check;

alter table public.customer_payment_transactions
  add constraint customer_payment_transactions_status_check
  check (status in ('created', 'paid', 'failed'));

create table if not exists public.chatbot_sessions (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  session_id text not null,
  user_id text,
  source_url text,
  referrer_url text,
  current_page text,
  device_type text,
  browser_name text,
  browser_version text,
  os_name text,
  country_code text,
  country_name text,
  city text,
  timezone text,
  locale text,
  screen_width integer,
  screen_height integer,
  opened_at timestamptz,
  started_at timestamptz,
  last_seen_at timestamptz not null default now(),
  ended_at timestamptz,
  duration_seconds integer not null default 0,
  message_count integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint chatbot_sessions_customer_session_unique unique (customer_id, session_id)
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
  collect_email boolean not null default false,
  collect_mobile boolean not null default false,
  verify_email_otp boolean not null default false,
  notify_lead_by_email boolean not null default false,
  notification_email text,
  redirect_whatsapp boolean not null default false,
  whatsapp_mobile_number text,
  verify_mobile_otp boolean not null default false,
  whatsapp_redirect_charged_at timestamptz,
  whatsapp_redirect_refund_deadline timestamptz,
  whatsapp_redirect_period_end timestamptz,
  whatsapp_redirect_charge_txn_id bigint,
  whatsapp_redirect_charge_amount_paise integer,
  whatsapp_redirect_refunded_at timestamptz,
  whatsapp_redirect_stopped_at timestamptz,
  whatsapp_redirect_stopped_reason text,
  whatsapp_redirect_failed_charge_amount_paise integer,
  whatsapp_redirect_toggle_date date,
  whatsapp_redirect_toggle_count integer not null default 0,
  whatsapp_redirect_locked_until timestamptz,
  service_tier text not null default 'free' check (service_tier in ('free', 'paid')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint lead_generation_settings_notification_email_check
    check (notification_email is null or notification_email ~* '^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$'),
  constraint lead_generation_settings_whatsapp_mobile_check
    check (whatsapp_mobile_number is null or whatsapp_mobile_number ~ '^\+?[1-9][0-9]{7,14}$')
);

alter table public.lead_generation_settings
  add column if not exists collect_email boolean not null default false;

alter table public.lead_generation_settings
  add column if not exists collect_mobile boolean not null default false;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_charged_at timestamptz;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_refund_deadline timestamptz;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_period_end timestamptz;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_charge_txn_id bigint;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_charge_amount_paise integer;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_refunded_at timestamptz;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_stopped_at timestamptz;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_stopped_reason text;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_failed_charge_amount_paise integer;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_toggle_date date;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_toggle_count integer not null default 0;

alter table public.lead_generation_settings
  add column if not exists whatsapp_redirect_locked_until timestamptz;

create table if not exists public.lead_generation_leads (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  user_id text not null,
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
  created_at timestamptz not null default now(),
  constraint lead_generation_leads_customer_user_unique unique (customer_id, user_id)
);

create table if not exists public.billing_accounts (
  id bigserial primary key,
  customer_id uuid unique references public.chatbot_signups(customer_id) on delete cascade,
  email text not null,
  wallet_balance_paise integer not null default 0,
  current_plan text not null default 'free',
  subscription_status text not null default 'free' check (subscription_status in ('free', 'active', 'expired', 'cancelled')),
  auto_recharge_enabled boolean not null default false,
  auto_recharge_threshold_paise integer not null default 0,
  auto_recharge_amount_paise integer not null default 0,
  saved_payment_method_status text not null default 'missing' check (saved_payment_method_status in ('missing', 'active', 'failed', 'revoked')),
  saved_payment_method_reference text,
  saved_payment_method_customer_id text,
  saved_payment_method_contact text,
  last_auto_recharge_attempt_at timestamptz,
  current_period_start timestamptz,
  current_period_end timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.billing_accounts
  add column if not exists auto_recharge_enabled boolean not null default false;

alter table public.billing_accounts
  add column if not exists auto_recharge_threshold_paise integer not null default 0;

alter table public.billing_accounts
  add column if not exists auto_recharge_amount_paise integer not null default 0;

alter table public.billing_accounts
  add column if not exists saved_payment_method_status text not null default 'missing';

alter table public.billing_accounts
  add column if not exists saved_payment_method_reference text;

alter table public.billing_accounts
  add column if not exists saved_payment_method_customer_id text;

alter table public.billing_accounts
  add column if not exists saved_payment_method_contact text;

alter table public.billing_accounts
  add column if not exists last_auto_recharge_attempt_at timestamptz;

alter table public.billing_accounts
  add column if not exists customer_id uuid references public.chatbot_signups(customer_id) on delete cascade;

alter table public.billing_accounts
  drop constraint if exists billing_accounts_email_key;

create unique index if not exists billing_accounts_customer_id_key
  on public.billing_accounts(customer_id)
  where customer_id is not null;

create table if not exists public.billing_orders (
  id bigserial primary key,
  email text not null,
  customer_id uuid references public.chatbot_signups(customer_id) on delete set null,
  plan_id text not null,
  order_type text not null default 'subscription' check (order_type in ('subscription', 'wallet', 'mandate')),
  amount_paise integer not null,
  currency text not null default 'INR',
  status text not null default 'created' check (status in ('created', 'paid', 'failed')),
  razorpay_order_id text unique,
  razorpay_payment_id text,
  razorpay_signature text,
  receipt text,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  paid_at timestamptz
);

alter table public.billing_orders
  drop constraint if exists billing_orders_order_type_check;

alter table public.billing_orders
  add constraint billing_orders_order_type_check
  check (order_type in ('subscription', 'wallet', 'mandate'));

create table if not exists public.wallet_transactions (
  id bigserial primary key,
  email text not null,
  customer_id uuid references public.chatbot_signups(customer_id) on delete set null,
  transaction_type text not null check (transaction_type in ('credit', 'debit')),
  amount_paise integer not null,
  balance_after_paise integer not null,
  description text,
  reference_type text,
  reference_id text,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);

create table if not exists public.customer_invoices (
  id bigserial primary key,
  invoice_number text not null unique,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  email text not null,
  plan_id text not null,
  invoice_type text not null default 'subscription' check (invoice_type in ('subscription', 'auto_recharge', 'manual', 'refund')),
  status text not null default 'paid' check (status in ('paid', 'refunded', 'void')),
  currency text not null default 'INR',
  subtotal_paise integer not null default 0,
  tax_paise integer not null default 0,
  total_paise integer not null default 0,
  payment_reference text,
  order_reference text,
  billing_period_start timestamptz,
  billing_period_end timestamptz,
  pdf_filename text,
  emailed_at timestamptz,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);

create table if not exists public.customer_api_keys (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  name text not null default 'API key',
  key_prefix text not null unique,
  key_hash text not null,
  allowed_ips text,
  allowed_origins text,
  rate_limit_per_day integer not null default 1000,
  last_used_at timestamptz,
  revoked_at timestamptz,
  created_at timestamptz not null default now()
);

create table if not exists public.customer_api_usage_logs (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  api_key_id bigint references public.customer_api_keys(id) on delete set null,
  endpoint text,
  ip_address text,
  origin text,
  status_code integer,
  created_at timestamptz not null default now()
);

create table if not exists public.support_tickets (
  id bigserial primary key,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  conversation_id bigint references public.chatbot_conversations(id) on delete set null,
  user_id text,
  user_question text not null,
  bot_response text,
  source_url text,
  status text not null default 'open' check (status in ('open', 'closed')),
  notification_email text,
  email_sent boolean not null default false,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  closed_at timestamptz
);

alter table public.faq_questions
  add column if not exists category text default 'General';

alter table public.faq_questions enable row level security;
alter table public.faq_action_suggestions enable row level security;
alter table public.customer_payment_settings enable row level security;
alter table public.customer_payment_actions enable row level security;
alter table public.customer_payment_transactions enable row level security;
alter table public.faq_scheduled_action_suggestions enable row level security;

create index if not exists idx_chatbot_settings_customer_id
  on public.chatbot_settings(customer_id);

create index if not exists idx_chatbot_conversations_customer_id_created_at
  on public.chatbot_conversations(customer_id, created_at desc);

create index if not exists idx_chatbot_conversations_status
  on public.chatbot_conversations(status);

create index if not exists idx_chatbot_conversations_session_id
  on public.chatbot_conversations(customer_id, session_id);

create index if not exists idx_chatbot_conversations_device
  on public.chatbot_conversations(customer_id, device_type);

create index if not exists idx_chatbot_sessions_customer_last_seen
  on public.chatbot_sessions(customer_id, last_seen_at desc);

create index if not exists idx_chatbot_sessions_customer_session
  on public.chatbot_sessions(customer_id, session_id);

create index if not exists idx_faq_questions_customer_category
  on public.faq_questions(customer_id, category);

create index if not exists idx_faq_action_suggestions_customer_faq
  on public.faq_action_suggestions(customer_id, faq_id, display_order);
create index if not exists idx_customer_payment_actions_customer
  on public.customer_payment_actions(customer_id, is_active, created_at desc);
create index if not exists idx_customer_payment_transactions_customer_created
  on public.customer_payment_transactions(customer_id, created_at desc);

create index if not exists idx_faq_scheduled_action_suggestions_customer_slot
  on public.faq_scheduled_action_suggestions(customer_id, slot_no);

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

create index if not exists idx_billing_accounts_email
  on public.billing_accounts(email);

create index if not exists idx_billing_accounts_customer_id
  on public.billing_accounts(customer_id);

create index if not exists idx_customer_api_keys_customer_id
  on public.customer_api_keys(customer_id);

create index if not exists idx_customer_api_keys_prefix
  on public.customer_api_keys(key_prefix);

create index if not exists idx_customer_api_usage_customer_created_at
  on public.customer_api_usage_logs(customer_id, created_at desc);

create index if not exists idx_support_tickets_customer_created_at
  on public.support_tickets(customer_id, created_at desc);

create index if not exists idx_faq_action_feedback_customer_created_at
  on public.faq_action_feedback(customer_id, created_at desc);

create index if not exists idx_billing_orders_email_created_at
  on public.billing_orders(email, created_at desc);

create index if not exists idx_billing_orders_customer_created_at
  on public.billing_orders(customer_id, created_at desc);

create index if not exists idx_wallet_transactions_email_created_at
  on public.wallet_transactions(email, created_at desc);

create index if not exists idx_wallet_transactions_customer_created_at
  on public.wallet_transactions(customer_id, created_at desc);

create index if not exists idx_customer_invoices_customer_created_at
  on public.customer_invoices(customer_id, created_at desc);

create index if not exists idx_customer_invoices_payment_reference
  on public.customer_invoices(payment_reference);

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

drop trigger if exists set_billing_accounts_updated_at on public.billing_accounts;
create trigger set_billing_accounts_updated_at
before update on public.billing_accounts
for each row
execute function public.set_updated_at();

drop trigger if exists set_chatbot_sessions_updated_at on public.chatbot_sessions;
create trigger set_chatbot_sessions_updated_at
before update on public.chatbot_sessions
for each row
execute function public.set_updated_at();

alter table public.chatbot_settings enable row level security;
alter table public.chatbot_signups enable row level security;
alter table public.chatbot_conversations enable row level security;
alter table public.faq_action_feedback enable row level security;
alter table public.chatbot_sessions enable row level security;
alter table public.customer_profiles enable row level security;
alter table public.lead_generation_settings enable row level security;
alter table public.lead_generation_leads enable row level security;
alter table public.billing_accounts enable row level security;
alter table public.billing_orders enable row level security;
alter table public.wallet_transactions enable row level security;
alter table public.customer_invoices enable row level security;
alter table public.customer_api_keys enable row level security;
alter table public.customer_api_usage_logs enable row level security;
alter table public.support_tickets enable row level security;

drop policy if exists "dashboard settings readable" on public.chatbot_settings;
create policy "dashboard settings readable"
on public.chatbot_settings
for select
to anon, authenticated
using (true);

drop policy if exists "dashboard chatbot signups readable" on public.chatbot_signups;
create policy "dashboard chatbot signups readable"
on public.chatbot_signups
for select
to anon, authenticated
using (true);

drop policy if exists "dashboard chatbot signups insertable" on public.chatbot_signups;
create policy "dashboard chatbot signups insertable"
on public.chatbot_signups
for insert
to anon, authenticated
with check (true);

drop policy if exists "dashboard chatbot signups updatable" on public.chatbot_signups;
create policy "dashboard chatbot signups updatable"
on public.chatbot_signups
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "dashboard chatbot signups deletable" on public.chatbot_signups;
create policy "dashboard chatbot signups deletable"
on public.chatbot_signups
for delete
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

drop policy if exists "faq action feedback readable" on public.faq_action_feedback;
create policy "faq action feedback readable"
on public.faq_action_feedback
for select
to anon, authenticated
using (true);

drop policy if exists "faq action feedback insertable" on public.faq_action_feedback;
create policy "faq action feedback insertable"
on public.faq_action_feedback
for insert
to anon, authenticated
with check (true);

drop policy if exists "dashboard sessions readable" on public.chatbot_sessions;
create policy "dashboard sessions readable"
on public.chatbot_sessions
for select
to anon, authenticated
using (true);

drop policy if exists "dashboard sessions insertable" on public.chatbot_sessions;
create policy "dashboard sessions insertable"
on public.chatbot_sessions
for insert
to anon, authenticated
with check (true);

drop policy if exists "dashboard sessions updatable" on public.chatbot_sessions;
create policy "dashboard sessions updatable"
on public.chatbot_sessions
for update
to anon, authenticated
using (true)
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

drop policy if exists "billing accounts readable" on public.billing_accounts;
create policy "billing accounts readable"
on public.billing_accounts
for select
to anon, authenticated
using (true);

drop policy if exists "billing accounts insertable" on public.billing_accounts;
create policy "billing accounts insertable"
on public.billing_accounts
for insert
to anon, authenticated
with check (true);

drop policy if exists "billing accounts updatable" on public.billing_accounts;
create policy "billing accounts updatable"
on public.billing_accounts
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "billing orders readable" on public.billing_orders;
create policy "billing orders readable"
on public.billing_orders
for select
to anon, authenticated
using (true);

drop policy if exists "billing orders insertable" on public.billing_orders;
create policy "billing orders insertable"
on public.billing_orders
for insert
to anon, authenticated
with check (true);

drop policy if exists "billing orders updatable" on public.billing_orders;
create policy "billing orders updatable"
on public.billing_orders
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "wallet transactions readable" on public.wallet_transactions;
create policy "wallet transactions readable"
on public.wallet_transactions
for select
to anon, authenticated
using (true);

drop policy if exists "wallet transactions insertable" on public.wallet_transactions;
create policy "wallet transactions insertable"
on public.wallet_transactions
for insert
to anon, authenticated
with check (true);

drop policy if exists "customer invoices readable" on public.customer_invoices;
create policy "customer invoices readable"
on public.customer_invoices
for select
to anon, authenticated
using (true);

drop policy if exists "customer invoices insertable" on public.customer_invoices;
create policy "customer invoices insertable"
on public.customer_invoices
for insert
to anon, authenticated
with check (true);

drop policy if exists "customer invoices updatable" on public.customer_invoices;
create policy "customer invoices updatable"
on public.customer_invoices
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "customer api keys readable" on public.customer_api_keys;
create policy "customer api keys readable"
on public.customer_api_keys
for select
to anon, authenticated
using (true);

drop policy if exists "customer api keys insertable" on public.customer_api_keys;
create policy "customer api keys insertable"
on public.customer_api_keys
for insert
to anon, authenticated
with check (true);

drop policy if exists "customer api keys updatable" on public.customer_api_keys;
create policy "customer api keys updatable"
on public.customer_api_keys
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "customer api usage readable" on public.customer_api_usage_logs;
create policy "customer api usage readable"
on public.customer_api_usage_logs
for select
to anon, authenticated
using (true);

drop policy if exists "customer api usage insertable" on public.customer_api_usage_logs;
create policy "customer api usage insertable"
on public.customer_api_usage_logs
for insert
to anon, authenticated
with check (true);

drop policy if exists "support tickets readable" on public.support_tickets;
create policy "support tickets readable"
on public.support_tickets
for select
to anon, authenticated
using (true);

drop policy if exists "support tickets insertable" on public.support_tickets;
create policy "support tickets insertable"
on public.support_tickets
for insert
to anon, authenticated
with check (true);

drop policy if exists "support tickets updatable" on public.support_tickets;
create policy "support tickets updatable"
on public.support_tickets
for update
to anon, authenticated
using (true)
with check (true);

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

drop policy if exists "faq action suggestions readable" on public.faq_action_suggestions;
create policy "faq action suggestions readable"
on public.faq_action_suggestions
for select
to anon, authenticated
using (true);

drop policy if exists "faq action suggestions insertable" on public.faq_action_suggestions;
create policy "faq action suggestions insertable"
on public.faq_action_suggestions
for insert
to anon, authenticated
with check (true);

drop policy if exists "faq action suggestions updatable" on public.faq_action_suggestions;
create policy "faq action suggestions updatable"
on public.faq_action_suggestions
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "faq action suggestions deletable" on public.faq_action_suggestions;
create policy "faq action suggestions deletable"
on public.faq_action_suggestions
for delete
to anon, authenticated
using (true);

drop policy if exists "faq scheduled action suggestions readable" on public.faq_scheduled_action_suggestions;
create policy "faq scheduled action suggestions readable"
on public.faq_scheduled_action_suggestions
for select
to anon, authenticated
using (true);

drop policy if exists "faq scheduled action suggestions insertable" on public.faq_scheduled_action_suggestions;
create policy "faq scheduled action suggestions insertable"
on public.faq_scheduled_action_suggestions
for insert
to anon, authenticated
with check (true);

drop policy if exists "faq scheduled action suggestions updatable" on public.faq_scheduled_action_suggestions;
create policy "faq scheduled action suggestions updatable"
on public.faq_scheduled_action_suggestions
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "faq scheduled action suggestions deletable" on public.faq_scheduled_action_suggestions;
create policy "faq scheduled action suggestions deletable"
on public.faq_scheduled_action_suggestions
for delete
to anon, authenticated
using (true);

drop policy if exists "customer payment settings readable" on public.customer_payment_settings;
create policy "customer payment settings readable" on public.customer_payment_settings for select to anon, authenticated using (true);
drop policy if exists "customer payment settings insertable" on public.customer_payment_settings;
create policy "customer payment settings insertable" on public.customer_payment_settings for insert to anon, authenticated with check (true);
drop policy if exists "customer payment settings updatable" on public.customer_payment_settings;
create policy "customer payment settings updatable" on public.customer_payment_settings for update to anon, authenticated using (true) with check (true);

drop policy if exists "customer payment actions readable" on public.customer_payment_actions;
create policy "customer payment actions readable" on public.customer_payment_actions for select to anon, authenticated using (true);
drop policy if exists "customer payment actions insertable" on public.customer_payment_actions;
create policy "customer payment actions insertable" on public.customer_payment_actions for insert to anon, authenticated with check (true);
drop policy if exists "customer payment actions updatable" on public.customer_payment_actions;
create policy "customer payment actions updatable" on public.customer_payment_actions for update to anon, authenticated using (true) with check (true);
drop policy if exists "customer payment actions deletable" on public.customer_payment_actions;
create policy "customer payment actions deletable" on public.customer_payment_actions for delete to anon, authenticated using (true);

drop policy if exists "customer payment transactions readable" on public.customer_payment_transactions;
create policy "customer payment transactions readable" on public.customer_payment_transactions for select to anon, authenticated using (true);
drop policy if exists "customer payment transactions insertable" on public.customer_payment_transactions;
create policy "customer payment transactions insertable" on public.customer_payment_transactions for insert to anon, authenticated with check (true);
drop policy if exists "customer payment transactions updatable" on public.customer_payment_transactions;
create policy "customer payment transactions updatable" on public.customer_payment_transactions for update to anon, authenticated using (true) with check (true);

grant select, insert, update, delete on public.chatbot_settings to anon, authenticated;
grant select, insert, update, delete on public.chatbot_signups to anon, authenticated;
grant select, insert, update, delete on public.chatbot_conversations to anon, authenticated;
grant select, insert on public.faq_action_feedback to anon, authenticated;
grant select, insert, update, delete on public.chatbot_sessions to anon, authenticated;
grant select, insert, update, delete on public.customer_profiles to anon, authenticated;
grant select, insert, update, delete on public.faq_questions to anon, authenticated;
grant select, insert, update, delete on public.faq_action_suggestions to anon, authenticated;
grant select, insert, update, delete on public.faq_scheduled_action_suggestions to anon, authenticated;
grant select, insert, update on public.customer_payment_settings to anon, authenticated;
grant select, insert, update, delete on public.customer_payment_actions to anon, authenticated;
grant select, insert, update on public.customer_payment_transactions to anon, authenticated;
grant select, insert, update, delete on public.lead_generation_settings to anon, authenticated;
grant select, insert, update, delete on public.lead_generation_leads to anon, authenticated;
grant select, insert, update, delete on public.billing_accounts to anon, authenticated;
grant select, insert, update, delete on public.billing_orders to anon, authenticated;
grant select, insert on public.wallet_transactions to anon, authenticated;
grant select, insert, update on public.customer_invoices to anon, authenticated;
grant select, insert, update on public.customer_api_keys to anon, authenticated;
grant select, insert on public.customer_api_usage_logs to anon, authenticated;
grant select, insert, update on public.support_tickets to anon, authenticated;
grant update(password) on public.customers to anon, authenticated;
alter table public.customers
  add column if not exists must_reset_password boolean not null default false;
grant update(password, must_reset_password) on public.customers to anon, authenticated;

create table if not exists public.customer_remember_tokens (
  id uuid primary key default gen_random_uuid(),
  email text not null,
  selector text not null unique,
  token_hash text not null,
  expires_at timestamptz not null,
  user_agent text,
  created_at timestamptz not null default now(),
  last_used_at timestamptz
);

create table if not exists public.ai_scan_jobs (
  id uuid primary key default gen_random_uuid(),
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  email text not null,
  website_url text not null,
  website_domain text not null,
  status text not null default 'pending' check (status in ('pending', 'running', 'completed', 'failed')),
  provider text,
  model text,
  pages_requested integer not null default 0,
  pages_scanned integer not null default 0,
  pages_failed integer not null default 0,
  error_message text,
  worker_id text,
  locked_until timestamptz,
  started_at timestamptz,
  completed_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists ai_scan_jobs_customer_id_idx
on public.ai_scan_jobs(customer_id);

create index if not exists ai_scan_jobs_status_idx
on public.ai_scan_jobs(status);

alter table public.ai_scan_jobs
  add column if not exists worker_id text;

alter table public.ai_scan_jobs
  add column if not exists locked_until timestamptz;

create index if not exists ai_scan_jobs_worker_idx
on public.ai_scan_jobs(status, locked_until);

create table if not exists public.ai_website_pages (
  id uuid primary key default gen_random_uuid(),
  scan_job_id uuid not null references public.ai_scan_jobs(id) on delete cascade,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  url text not null,
  normalized_url text not null,
  page_title text,
  page_status text not null default 'pending' check (page_status in ('pending', 'fetched', 'summarized', 'failed')),
  http_status integer,
  content_hash text,
  clean_text text,
  summary_json jsonb,
  embedding jsonb,
  ai_error text,
  content_type text,
  content_length integer not null default 0,
  discovered_links_count integer not null default 0,
  html_preview text,
  context_edited boolean not null default false,
  summary_edited boolean not null default false,
  fetched_at timestamptz,
  summarized_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (customer_id, normalized_url)
);

alter table public.ai_website_pages
  add column if not exists content_type text;

alter table public.ai_website_pages
  add column if not exists content_length integer not null default 0;

alter table public.ai_website_pages
  add column if not exists discovered_links_count integer not null default 0;

alter table public.ai_website_pages
  add column if not exists html_preview text;

alter table public.ai_website_pages
  add column if not exists context_edited boolean not null default false;

alter table public.ai_website_pages
  add column if not exists summary_edited boolean not null default false;

alter table public.ai_website_pages
  add column if not exists crawl_attempts integer not null default 0;

alter table public.ai_website_pages
  add column if not exists next_retry_at timestamptz;

alter table public.ai_website_pages
  add column if not exists summary_attempts integer not null default 0;

alter table public.ai_website_pages
  add column if not exists summary_next_retry_at timestamptz;

create index if not exists ai_website_pages_scan_job_id_idx
on public.ai_website_pages(scan_job_id);

create index if not exists ai_website_pages_customer_id_idx
on public.ai_website_pages(customer_id);

create index if not exists ai_website_pages_pending_idx
on public.ai_website_pages(scan_job_id, page_status, next_retry_at);

create index if not exists ai_website_pages_summary_idx
on public.ai_website_pages(scan_job_id, page_status, summary_next_retry_at);

create table if not exists public.ai_website_faqs (
  id uuid primary key default gen_random_uuid(),
  scan_job_id uuid not null references public.ai_scan_jobs(id) on delete cascade,
  customer_id uuid not null references public.chatbot_signups(customer_id) on delete cascade,
  page_url text not null,
  question text not null,
  answer text not null,
  source text not null default 'ai',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (customer_id, page_url, question)
);

create index if not exists ai_website_faqs_scan_job_id_idx
on public.ai_website_faqs(scan_job_id);

create index if not exists ai_website_faqs_customer_id_idx
on public.ai_website_faqs(customer_id);

create index if not exists ai_website_faqs_customer_question_idx
on public.ai_website_faqs(customer_id, lower(question));

alter table public.ai_scan_jobs enable row level security;
alter table public.ai_website_pages enable row level security;
alter table public.ai_website_faqs enable row level security;

drop policy if exists "ai scan jobs readable" on public.ai_scan_jobs;
create policy "ai scan jobs readable"
on public.ai_scan_jobs
for select
to anon, authenticated
using (true);

drop policy if exists "ai scan jobs insertable" on public.ai_scan_jobs;
create policy "ai scan jobs insertable"
on public.ai_scan_jobs
for insert
to anon, authenticated
with check (true);

drop policy if exists "ai scan jobs updatable" on public.ai_scan_jobs;
create policy "ai scan jobs updatable"
on public.ai_scan_jobs
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "ai website pages readable" on public.ai_website_pages;
create policy "ai website pages readable"
on public.ai_website_pages
for select
to anon, authenticated
using (true);

drop policy if exists "ai website pages insertable" on public.ai_website_pages;
create policy "ai website pages insertable"
on public.ai_website_pages
for insert
to anon, authenticated
with check (true);

drop policy if exists "ai website pages updatable" on public.ai_website_pages;
create policy "ai website pages updatable"
on public.ai_website_pages
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "ai website faqs readable" on public.ai_website_faqs;
create policy "ai website faqs readable"
on public.ai_website_faqs
for select
to anon, authenticated
using (true);

drop policy if exists "ai website faqs insertable" on public.ai_website_faqs;
create policy "ai website faqs insertable"
on public.ai_website_faqs
for insert
to anon, authenticated
with check (true);

drop policy if exists "ai website faqs updatable" on public.ai_website_faqs;
create policy "ai website faqs updatable"
on public.ai_website_faqs
for update
to anon, authenticated
using (true)
with check (true);

create index if not exists customer_remember_tokens_email_idx
on public.customer_remember_tokens(email);

create index if not exists customer_remember_tokens_expires_idx
on public.customer_remember_tokens(expires_at);

alter table public.customer_remember_tokens enable row level security;

drop policy if exists "remember tokens readable" on public.customer_remember_tokens;
create policy "remember tokens readable"
on public.customer_remember_tokens
for select
to anon, authenticated
using (true);

drop policy if exists "remember tokens insertable" on public.customer_remember_tokens;
create policy "remember tokens insertable"
on public.customer_remember_tokens
for insert
to anon, authenticated
with check (true);

drop policy if exists "remember tokens updatable" on public.customer_remember_tokens;
create policy "remember tokens updatable"
on public.customer_remember_tokens
for update
to anon, authenticated
using (true)
with check (true);

drop policy if exists "remember tokens deletable" on public.customer_remember_tokens;
create policy "remember tokens deletable"
on public.customer_remember_tokens
for delete
to anon, authenticated
using (true);

grant select, insert, update, delete on public.customer_remember_tokens to anon, authenticated;
grant select, insert, update on public.ai_scan_jobs to anon, authenticated;
grant select, insert, update on public.ai_website_pages to anon, authenticated;
grant select, insert, update on public.ai_website_faqs to anon, authenticated;
grant usage, select on sequence public.chatbot_settings_id_seq to anon, authenticated;
grant usage, select on sequence public.chatbot_conversations_id_seq to anon, authenticated;
grant usage, select on sequence public.faq_action_feedback_id_seq to anon, authenticated;
grant usage, select on sequence public.chatbot_sessions_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_profiles_id_seq to anon, authenticated;
grant usage, select on sequence public.faq_action_suggestions_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_payment_settings_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_payment_actions_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_payment_transactions_id_seq to anon, authenticated;
grant usage, select on sequence public.faq_scheduled_action_suggestions_id_seq to anon, authenticated;
grant usage, select on sequence public.lead_generation_settings_id_seq to anon, authenticated;
grant usage, select on sequence public.lead_generation_leads_id_seq to anon, authenticated;
grant usage, select on sequence public.billing_accounts_id_seq to anon, authenticated;
grant usage, select on sequence public.billing_orders_id_seq to anon, authenticated;
grant usage, select on sequence public.wallet_transactions_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_invoices_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_api_keys_id_seq to anon, authenticated;
grant usage, select on sequence public.customer_api_usage_logs_id_seq to anon, authenticated;
grant usage, select on sequence public.support_tickets_id_seq to anon, authenticated;
