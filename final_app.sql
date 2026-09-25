-- ============================================================
-- final_app.sql
-- Run this script inside HeidiSQL (File > Run SQL file...)
-- to create the database and ALL tables used by the app:
--   users         (accounts + email verification + MFA columns)
--   user_devices  (trusted device logins for the settings panel)
--   messages      (thread-aware Messenger messages)
--   providers     (islaFIND service profiles created by users)
--   inquiries     (client-to-provider message requests)
--   conversations (in-app messenger threads, provider-client pairs)
--   saved_listings (bookmarked listings per user)
--
-- IMPORTANT: every table uses CREATE TABLE IF NOT EXISTS, so this
-- script is safe to re-run on an existing database — it never drops
-- or rewrites data.
--   service_contracts (hired jobs; rating eligibility)
--   reviews       (post-service ratings, gated by completed contracts)
--   user_interactions (view/search/inquiry history for recommendations)
--   login_attempts (server-side failed-login counters, for throttling)
-- ============================================================

-- Create the database if it does not already exist.
CREATE DATABASE IF NOT EXISTS final_app
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Switch to the newly created database.
USE final_app;

-- ============================================================
-- users table
-- id            : internal primary key
-- user_id       : random custom ID (0 - 500,000), shown on the profile
-- full_name     : the user's full name (required)
-- email         : login identifier #1, must be unique
-- phone         : login identifier #2, must be unique (1 number = 1 account)
-- date_of_birth : used to enforce the 18+ age rule and to show age
-- password_hash : bcrypt hash produced by password_hash()
-- profile_picture : file path of the uploaded avatar (NULL = none yet)
-- is_verified   : 1 once the e-mailed verification code is confirmed
-- verification_code : 6-digit code pending e-mail confirmation (NULL when done)
-- mfa_enabled   : 1 when login also requires a one-time E-MAILED code (MFA)
-- created_at    : auto-filled with the registration date/time
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED    NOT NULL UNIQUE,          -- random 0 - 500,000
    full_name         VARCHAR(100)    NOT NULL,
    email             VARCHAR(255)    NOT NULL UNIQUE,
    phone             VARCHAR(20)     NOT NULL UNIQUE,
    date_of_birth     DATE            NOT NULL,
    password_hash     VARCHAR(255)    NOT NULL,
    profile_picture   VARCHAR(255)    NULL,                     -- uploads/xxx.jpg
    is_verified       TINYINT(1)      NOT NULL DEFAULT 0,       -- e-mail confirmed?
    verification_code VARCHAR(10)     NULL,                     -- pending 6-digit code
    mfa_enabled       TINYINT(1)      NOT NULL DEFAULT 0,       -- MFA on login?
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

-- ============================================================
-- user_devices table (Device Login settings)
-- id          : primary key
-- user_id     : which user owns this device (users.user_id)
-- device_name : e.g. "Chrome / Desktop" parsed from the User-Agent
-- ip_address  : the IP the device logged in from
-- session_id  : ties the row to a live PHP session (unique)
-- last_login  : last time this session logged in
-- is_active   : 0 once the session is logged out or revoked
-- ============================================================
CREATE TABLE IF NOT EXISTS user_devices (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    session_id  VARCHAR(128) NOT NULL,
    last_login  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_session (session_id),
    KEY idx_devices_user (user_id)
) ENGINE = InnoDB;

-- ============================================================
-- providers table (islaFIND service profiles — streamlined)
-- id              : primary key
-- user_id         : which user created this profile (users.id)
-- profile_type    : 'individual' (a person's skill) or 'business'
-- name            : listing name. Required for BUSINESS listings;
--                   NULL for INDIVIDUAL listings (those show the
--                   account holder's full name instead).
-- selected_title  : the job title / business type the user picked
--                   from the search (e.g. mechanic, beach_resort,
--                   sari_sari_store)
-- barangay        : Bantayan Island barangay (cascades from the
--                   chosen municipality; includes islet barangays)
-- municipality    : Bantayan Island municipality (Santa Fe, Bantayan
--                   or Madridejos) — the barangay list depends on it
-- latitude/longitude : GPS pin captured on the create/edit form
--                   (DECIMAL so map links can use full precision)
-- google_maps_url : optional stored Google Maps deep link; the
--                   directory View Map button falls back to building
--                   one from latitude/longitude when this is empty
-- view_count      : how many times the profile was viewed
-- interaction_count : views + inquiries + searches (popularity)
-- created_at      : auto-filled with the listing date
-- NOTE: phone and profile_picture are NOT stored here — profile
-- cards inherit them from the user's main account (users table).
-- profile_description: free-text blurb for INDIVIDUAL SKILLS
--                      listings (experience, rates, job details).
-- unit_inventory     : available-unit count for BUSINESS listings
--                      (rooms, motorbikes, beds, stock, etc.).
-- Only ONE of the two is filled, depending on profile_type.
-- average_rating + review_count: aggregate metrics kept in sync
-- with the reviews table by rate_service.php so profile cards
-- always reflect the latest earned ratings.
-- ============================================================
CREATE TABLE IF NOT EXISTS providers (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED  NOT NULL,
    profile_type    VARCHAR(20)   NOT NULL DEFAULT 'business',
    name            VARCHAR(120)  NULL,
    profile_description TEXT      NULL,
    unit_inventory  INT UNSIGNED  NULL,
    selected_title  VARCHAR(40)   NOT NULL,
    barangay        VARCHAR(60)   NULL,
    municipality    VARCHAR(100)  NULL,
    latitude        DECIMAL(10, 8) NULL,
    longitude       DECIMAL(11, 8) NULL,
    google_maps_url TEXT          NULL,
    view_count      INT UNSIGNED  NOT NULL DEFAULT 0,
    interaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    average_rating  FLOAT         NOT NULL DEFAULT 0,
    review_count    INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_providers_user (user_id),
    KEY idx_providers_title (selected_title),
    CONSTRAINT fk_providers_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- conversations table (in-app messenger threads)
-- id          : primary key
-- provider_id : the provider's user account (users.id)
-- client_id   : the client's user account (users.id)
-- created_at  : auto-filled with the thread creation time
-- status      : 'pending' = message request awaiting the
--               provider's acceptance; 'accepted' = open chat;
--               'declined' = the provider rejected the request.
-- A new "Inquire Availability" creates a PENDING request: the
-- pair can only talk once the provider accepts it. Existing
-- threads default to 'accepted' so they keep working.
-- One thread per provider-client pair (UNIQUE on the pair).
-- ============================================================
CREATE TABLE IF NOT EXISTS conversations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    client_id   INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status      ENUM('pending','accepted','declined') NOT NULL DEFAULT 'accepted',
    PRIMARY KEY (id),
    UNIQUE KEY uq_pair (provider_id, client_id),
    CONSTRAINT fk_conv_provider FOREIGN KEY (provider_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_client FOREIGN KEY (client_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- messages table (Messenger) - thread-aware
-- id              : primary key
-- conversation_id : which conversation this message belongs to
-- sender_id       : who sent the message (users.id)
-- recipient_id    : who receives it (users.id)
-- message         : the text content
-- is_read         : 0 until the recipient opens the chat thread
-- deleted_by / deleted_by2 : soft-delete flags — one slot per side
--                   of the pair, so deletion is INDEPENDENT per user:
--                   user A deleting sets their slot (A), user B
--                   deleting sets the other slot (B). Each user's
--                   thread only vanishes from THEIR OWN inbox; the
--                   counterparty keeps their copy until they delete
--                   it too. The slot is chosen by the delete handler
--                   (delete_conversations.php).
-- msg_deleted_by   : per-MESSAGE soft-delete — the user id who hid
--                   THIS single message from their own thread view
--                   (long-press a bubble to delete it). Independent
--                   of deleted_by: deleting one message must not
--                   remove the whole conversation, and a new message
--                   must not resurrect it.
-- created_at      : auto-filled with the send time
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NULL,
    sender_id       INT UNSIGNED NOT NULL,
    recipient_id    INT UNSIGNED NOT NULL,
    message         TEXT         NOT NULL,
    kind            ENUM('chat','system') NOT NULL DEFAULT 'chat',
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    deleted_by      INT UNSIGNED NULL DEFAULT NULL,
    deleted_by2     INT UNSIGNED NULL DEFAULT NULL,
    msg_deleted_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pair (sender_id, recipient_id),
    KEY idx_inbox (recipient_id, is_read),
    KEY idx_deleted_by (deleted_by),
    CONSTRAINT fk_msg_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- service_contracts table (hired jobs + rating eligibility)
-- id                  : primary key
-- provider_id         : the provider's user account (users.id)
-- provider_listing_id : the SPECIFIC islaFIND listing that was
--                       hired (providers.id). A user can own many
--                       listings, so the contract must remember
--                       which one the client hired — the rating
--                       prompt and reviews attach to this exact
--                       listing, never to the user's other ones.
-- client_id           : the client's user account (users.id)
-- status              : pending | pending_hire | accepted | declined | completed | cancelled
-- is_rated            : 1 once the client submits their review
-- created_at          : auto-filled when the hire is recorded
-- Only a client with a COMPLETED contract may rate the provider.
-- ============================================================
CREATE TABLE IF NOT EXISTS service_contracts (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id         INT UNSIGNED NOT NULL,
    provider_listing_id INT UNSIGNED NULL,
    client_id           INT UNSIGNED NOT NULL,
    status              ENUM('pending','pending_hire','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'pending',
    is_rated            TINYINT(1)   NOT NULL DEFAULT 0,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sc_provider (provider_id),
    KEY idx_sc_client (client_id),
    KEY idx_sc_listing (provider_listing_id),
    CONSTRAINT fk_sc_provider FOREIGN KEY (provider_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_client FOREIGN KEY (client_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- inquiries table (client-to-provider message requests)
-- id           : primary key
-- sender_id    : which user sent the inquiry (users.id)
-- provider_id  : which provider listing received it (providers.id)
-- message_text : the client's message (e.g. availability question)
-- status       : pending | available | replied
-- created_at   : auto-filled with the send time
-- ============================================================
CREATE TABLE IF NOT EXISTS inquiries (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id    INT UNSIGNED NOT NULL,
    provider_id  INT UNSIGNED NOT NULL,
    message_text TEXT         NOT NULL,
    status       ENUM('pending','available','replied') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inq_sender (sender_id),
    KEY idx_inq_provider (provider_id),
    CONSTRAINT fk_inq_sender FOREIGN KEY (sender_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_inq_provider FOREIGN KEY (provider_id)
        REFERENCES providers (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- reviews table (post-service ratings + comments)
-- id                  : primary key
-- service_contract_id : the COMPLETED job this rating refers to
--                       (UNIQUE: one rating per job)
-- provider_id         : which provider is being rated (providers.id)
-- user_id             : which user wrote the review (users.id)
-- rating              : 1 to 5 stars
-- comment             : optional written feedback
-- created_at          : auto-filled with the review time
-- ============================================================
CREATE TABLE IF NOT EXISTS reviews (
    id                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    service_contract_id INT UNSIGNED   NULL,
    provider_id         INT UNSIGNED   NOT NULL,
    user_id             INT UNSIGNED   NOT NULL,
    rating              TINYINT UNSIGNED NOT NULL,
    comment             TEXT           NULL,
    created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rev_contract (service_contract_id),
    KEY idx_reviews_provider (provider_id),
    CONSTRAINT fk_rev_contract FOREIGN KEY (service_contract_id)
        REFERENCES service_contracts (id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_provider FOREIGN KEY (provider_id)
        REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- user_interactions table (behavioral history)
-- id          : primary key
-- user_id     : which user performed the action (users.id)
-- provider_id : the provider involved (NULL for pure searches)
-- category    : the provider's category at the time (affinity)
-- action      : view | search | inquiry
-- created_at  : auto-filled with the action time
-- ============================================================
CREATE TABLE IF NOT EXISTS user_interactions (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED  NOT NULL,
    provider_id INT UNSIGNED  NULL,
    category    VARCHAR(40)   NOT NULL DEFAULT '',
    action      VARCHAR(20)   NOT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_int_user (user_id),
    KEY idx_int_provider (provider_id),
    CONSTRAINT fk_int_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_int_provider FOREIGN KEY (provider_id)
        REFERENCES providers (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- saved_listings table (bookmarked listings)
-- id          : primary key
-- user_id     : who bookmarked the listing (users.id)
-- provider_id : the bookmarked listing (providers.id)
-- created_at  : auto-filled when it was saved
-- UNIQUE (user_id, provider_id) makes saving idempotent: tapping
-- the heart twice can never create a duplicate row, and the toggle
-- handler can simply INSERT IGNORE / DELETE on the same pair.
-- Both foreign keys cascade, so deleting a user or a listing
-- (or the account-deletion flow) cleans its bookmarks up.
-- The app also creates this table at runtime (see db.php) so an
-- existing database picks the feature up without a manual import.
-- ============================================================
CREATE TABLE IF NOT EXISTS saved_listings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saved_pair (user_id, provider_id),
    KEY idx_saved_user (user_id),
    CONSTRAINT fk_saved_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_provider FOREIGN KEY (provider_id)
        REFERENCES providers (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- login_attempts table (failed-login throttling counters)
-- id              : primary key
-- scope           : which key this counter belongs to — 'account'
--                   (the email/phone typed on the login form) or
--                   'ip' (the address the attempt came from)
-- subject         : the value being counted. The identifier is
--                   lower-cased before it is stored, because MySQL's
--                   _ci collation makes "Alice@X.com" and
--                   "alice@x.com" the SAME account — folding case
--                   here is what stops a fresh counter per capital.
-- failures        : failed attempts in the current streak
-- first_failed_at : when the streak started
-- last_failed_at  : when it was last added to. A streak that has sat
--                   quiet for the decay window restarts at 1.
-- UNIQUE (scope, subject) lets login.php bump the row with one
-- INSERT ... ON DUPLICATE KEY UPDATE, and idx_login_last serves the
-- decay check plus the prune of rows nobody has failed against in
-- a long while. There is no foreign key: "subject" is often a typed
-- identifier that matches no account, or an IP address.
-- The app also creates this table at runtime (see db.php) so an
-- existing database picks the feature up without a manual import.
-- ============================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope           ENUM('account', 'ip') NOT NULL,
    subject         VARCHAR(190) NOT NULL,
    failures        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_failed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_failed_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_login_key (scope, subject),
    KEY idx_login_last (last_failed_at)
) ENGINE = InnoDB;
