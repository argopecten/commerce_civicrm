# TODO — Open Issues & Development Backlog

Current open items, ordered by priority. Closed work is not tracked here —
see the git history.

| # | Title | Priority |
|---|-------|----------|
| 1 | Kernel/functional test coverage for the order pipeline | Medium |
| 2 | Double opt-in and welcome e-mails are stubs | Medium |
| 3 | Admin settings UI for `commerce_civicrm.settings` | Low |
| 4 | Contribution currency fallback is hardcoded `EUR` | Low |
| 5 | `MembershipUpdater::cancelMembershipFromOrder()` has no internal callers | Low |
| 6 | Logger property type inconsistency across services | Low |

---

## 1. Kernel/functional test coverage for the order pipeline

**Status**: only unit tests exist (`AmountSplitterTest`,
`OrderCompleteSubscriberTest`). `OrderCivicrmUpdater::processOrder()` /
`createCiviOrder()`, cancellation and `RenewalProcessor` are verified
manually against a real CiviCRM instance.

**Direction**: kernel tests with mocked CiviCRM facades are of limited value
because the interesting behaviour (Order API Pending → Payment → Completed
flow, membership renewal via line-item `entity_id`) lives in CiviCRM. A
functional test setup with a real CiviCRM schema (as in CiviCRM's own
`civicrm/civicrm-core` test harness) would be needed for meaningful coverage.
Until then, keep [testing.md](testing.md)'s manual checklist authoritative.

## 2. Double opt-in and welcome e-mails are stubs

**Files**: `MailingUpdater::sendDoubleOptInEmail()`, `::sendWelcomeMessage()`

**Status**: the `double_opt_in` preference correctly stores the GroupContact
with status `Pending`, and `send_welcome` is honoured as a flag, but both
send methods only log — no e-mail is sent and there is no confirmation route,
so a `Pending` subscription is never promoted to `Added`.

**Suggested fix**: token generation + a Drupal confirmation route for double
opt-in; CiviCRM `MessageTemplate` API for the welcome message.

## 3. Admin settings UI for `commerce_civicrm.settings`

**Status**: all module settings (transitions, contact fallback, payment
instrument map, membership date mode) are managed via `drush config:set` /
config sync only.

**Suggested fix**: a settings form under Commerce configuration; transitions
could offer checkboxes derived from the installed order workflows.

## 4. Contribution currency fallback is hardcoded `EUR`

**File**: `OrderCivicrmUpdater::createCiviOrder()` —
`'currency' => $currency ?: 'EUR'`.

**Status**: directives built from order items always carry the order item's
currency, so the fallback only triggers for event-supplied directives without
a `currency` key — but when it does, `EUR` is arbitrary.

**Suggested fix**: fall back to the order's `total_price` currency (or make
it configurable) instead of a hardcoded code.

## 5. `MembershipUpdater::cancelMembershipFromOrder()` has no internal callers

**Status**: cancellation walks contribution line items and uses
`cancelMembershipById()`; `cancelMembershipFromOrder()` (contact + type
lookup variant) is kept as a public API for callers that have no line-item
link.

**Suggested fix**: either mark it `@api` explicitly or remove it.

## 6. Logger property type inconsistency across services

**Files**: `OrderCivicrmUpdater`, `RenewalProcessor`, `ProductFormHelper`
declare `$logger` as `Psr\Log\LoggerInterface`; the other services use
`Drupal\Core\Logger\LoggerChannelInterface`.

**Status**: harmless (`LoggerChannelInterface` extends the PSR interface) but
inconsistent.

**Suggested fix**: settle on one type (the PSR interface is the looser,
preferable dependency) across all services.
