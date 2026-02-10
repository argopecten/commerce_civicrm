# TODO — Open Issues & Development Backlog

> Commerce CiviCRM module — code review performed 2026-02-09.
> Updated 2026-02-10 after implementing prompts 01–30.
> Code review 2026-02-10: 4 Low-priority items (#33–#36) added.
> Targets: Drupal 11+, Commerce 3+, Drush 13.7+, PHP 8.3+, CiviCRM 6.x.

---

## Quick Reference

| #  | Title | Priority | Source | Status |
|----|-------|----------|--------|--------|
| 1  | ~~Missing helper functions — fatal errors~~ | Critical | D7 audit | **Done** |
| 2  | ~~Address fields written incorrectly to Contact~~ | Critical | CiviCRM 6.x | **Done** |
| 3  | ~~`custom_commerce_order_id` silently ignored~~ | Critical | D7 + CiviCRM 6.x | **Done** |
| 4  | ~~Order cancellation stubs~~ | High | Feature gap | **Done** |
| 5  | ~~MembershipUpdater procedural API4 → OOP~~ | High | API4 audit | **Done** |
| 6  | ~~No Contribution↔Membership LineItem linking~~ | High | CiviCRM 6.x | **Done** |
| 7  | ~~Fragile contribution lookup (LIKE search)~~ | High | CiviCRM 6.x | **Done** (via #3) |
| 8  | ~~Contact dedup by first+last name only~~ | High | CiviCRM 6.x | **Done** |
| 9  | ~~Membership dates bypass CiviCRM native logic~~ | High | CiviCRM 6.x | **Done** |
| 10 | ~~350+ line procedural form alter~~ | High | D7 audit | **Done** |
| 11 | ~~Static `\Drupal::` calls in CivicrmHelper~~ | High | D7 audit | **Done** |
| 12 | ~~Redundant `initializeCivicrm()` wrappers~~ | High | D7 audit | **Done** |
| 13 | ~~Mailing list stubs (double opt-in / welcome)~~ | Medium | Feature gap | **Done** |
| 14 | ~~`print_r()` in production logging~~ | Medium | D7 audit | **Done** |
| 15 | ~~No PHP return-type declarations~~ | Medium | D7 audit | **Done** |
| 16 | ~~No typed properties~~ | Medium | D7 audit | **Done** |
| 17 | ~~Dead `ContainerInjectionInterface` on subscriber~~ | Medium | D7 audit | **Done** |
| 18 | ~~Deprecated `REQUIREMENT_*` constants~~ | Medium | D7 audit | **Done** |
| 19 | ~~`hook_requirements()` → `hook_runtime_requirements()`~~ | Medium | D7 audit | **Done** |
| 20 | ~~`composer.json` inconsistencies~~ | Medium | D7 audit | **Done** |
| 21 | ~~Generic exception catching~~ | Medium | D7 audit | **Done** |
| 22 | ~~Views config D7-style~~ | Medium | D7 audit | **Done** |
| 23 | ~~`t()` function in service classes~~ | Medium | D7 audit | **Done** |
| 24 | ~~Inconsistent date formatting~~ | Medium | D7 + CiviCRM 6.x | **Done** |
| 25 | ~~`_` prefixed procedural functions~~ | Low | D7 audit | **Done** (via #10) |
| 26 | ~~Unused `use` imports~~ | Low | D7 audit | **Done** (via #16) |
| 27 | ~~Constructor property promotion not used~~ | Low | D7 audit | **Done** |
| 28 | ~~FinancialType `name` vs `label` display~~ | Low | CiviCRM 6.x | **Done** |
| 29 | ~~No CiviCRM maintenance mode awareness~~ | Low | CiviCRM 6.x | **Done** |
| 30 | ~~`composer.json` CiviCRM version constraint too broad~~ | Low | CiviCRM 6.x | **Done** |
| 31 | ~~OrderCompleteSubscriber extensibility~~ | Future | Feature | **Deferred** → [FMO #31](../fmo/31-order-complete-subscriber-extensibility.md) |
| 32 | ~~Advanced CiviCRM integration features~~ | Future | Feature | **Deferred** → [FMO #32](../fmo/32-advanced-civicrm-integration.md) |
| 33 | Dead `cancelMembership()` method in MembershipUpdater | Low | Code review | Open |
| 34 | Duplicated custom field provisioning logic | Low | Code review | Open |
| 35 | Static `\Drupal::time()` calls in services | Low | Code review | Open |
| 36 | Logger type inconsistency (`Psr` vs `LoggerChannel`) | Low | Code review | Open |

---

## Critical

*All critical items have been resolved. See [Completed Items](#completed-items).*

*Duplicate processing bug (formerly #37) moved to [FMO #31 §3](../fmo/31-order-complete-subscriber-extensibility.md#3-duplicate-processing-bug).*

---

## High Priority

*All high priority items have been resolved. See [Completed Items](#completed-items).*

---

## Medium Priority

*All medium priority items have been resolved. See [Completed Items](#completed-items).*

---

## Low Priority

*Items #27–#30 have been resolved. See [Completed Items](#completed-items).*

### 33. Dead `cancelMembership()` method in MembershipUpdater

**File**: `MembershipUpdater.php` L421

**Issue**: `cancelMembership($contact_id, $membership_type_id, OrderInterface $order): bool` is never called anywhere in the codebase. Only `cancelMembershipFromOrder()` (L662) is used — called from `OrderCivicrmUpdater::processCancellation()`.

**Suggested fix**: Either remove `cancelMembership()` as dead code, or keep it explicitly as a public API for external callers and document it with `@api`. If removed, the method's logic is preserved in `cancelMembershipFromOrder()` which provides the same functionality with additional order-item tracking.

### 34. Duplicated custom field provisioning logic

**Files**: `ContributionUpdater.php` L594 (`ensureCustomFieldExists()`) and `OrderCivicrmUpdater.php` L573 (`ensureCommerceOrderCustomFieldExists()`)

**Issue**: Both methods create the `Commerce_Order` custom group and `commerce_order_id` custom field with identical API4 logic. This duplicates 40+ lines of code.

**Suggested fix**: Extract the provisioning into a shared method — either on `CivicrmHelper` (since it's infrastructure) or keep it on `ContributionUpdater` and have `OrderCivicrmUpdater` delegate to it via the injected `$contributionUpdater` dependency.

### 35. Static `\Drupal::time()` calls in services

**Files**: `ContributionUpdater.php` L100, `OrderCivicrmUpdater.php` L363

**Issue**: Two `\Drupal::time()->getRequestTime()` static service calls remain in `src/`. These were introduced by prompt #24 to replace bare `time()` calls, which was an improvement. However, the proper Drupal DI pattern is to inject `Drupal\Component\Datetime\TimeInterface`.

**Suggested fix**: Add `@datetime.time` to the service arguments for both `ContributionUpdater` and `OrderCivicrmUpdater` in `commerce_civicrm.services.yml`, add a `protected readonly TimeInterface $time` constructor parameter, and replace `\Drupal::time()->getRequestTime()` with `$this->time->getRequestTime()`.

### 36. Logger type inconsistency (`Psr` vs `LoggerChannel`)

**Files**: `OrderCivicrmUpdater.php` L15/L30/L32, `ProductFormHelper.php` L9/L21/L23

**Issue**: 6 of 8 service files declare `$logger` as `Drupal\Core\Logger\LoggerChannelInterface`, while `OrderCivicrmUpdater` and `ProductFormHelper` use `Psr\Log\LoggerInterface`. Since `LoggerChannelFactoryInterface::get()` returns `LoggerChannelInterface` (which extends `Psr\Log\LoggerInterface`), the PSR type is not wrong but is inconsistent.

**Suggested fix**: Change both files to use `LoggerChannelInterface` for consistency with the other 6 services.

---

## Future Enhancements

*All future items have been deferred to dedicated FMO documents:*

- **#31** — [OrderCompleteSubscriber extensibility](../fmo/31-order-complete-subscriber-extensibility.md)
- **#32** — [Advanced CiviCRM integration features](../fmo/32-advanced-civicrm-integration.md)

---

## Appendix A — CiviCRM API4 Audit Summary

| Service File | API Calls | Style | Status |
|--------------|-----------|-------|--------|
| `CivicrmHelper.php` | 2 | OOP `\Civi\Api4\*` | Correct |
| `ContactUpdater.php` | 13 | OOP `\Civi\Api4\*` | Correct |
| `ContributionUpdater.php` | 13 | OOP `\Civi\Api4\*` | Correct |
| `MailingUpdater.php` | 4 | OOP `\Civi\Api4\*` | Correct (added via #13) |
| `MembershipUpdater.php` | 13 | OOP `\Civi\Api4\*` | Correct (migrated via #5) |
| `OrderCivicrmUpdater.php` | 9 | OOP `\Civi\Api4\*` | Correct (added via #4, #6) |
| `ProductFormHelper.php` | 0 | N/A (delegates to services) | N/A |
| `commerce_civicrm.module` | 0 | N/A (delegates to services) | N/A |

**No API3 usage found anywhere in the codebase.**
**No legacy `CRM_*` class usage found in source code.**
**No procedural `civicrm_api4()` calls remain.**

## Appendix B — CiviCRM 6.x Compatibility Matrix

Reviewed against CiviCRM 6.0.0 (2025-03-05), 6.1.0 (2025-04-03), and 6.2.0 (2025-05-07). Module's `composer.json` declares `drupal/civicrm: ^6.1` (updated via #30).

| Area | Status | Notes |
|------|--------|-------|
| API4 procedural `civicrm_api4()` | Compatible | Still supported in 6.x |
| API4 OOP `\Civi\Api4\*` | Compatible | Preferred convention; no breaking changes |
| `checkPermissions` handling | Correct | Explicitly `FALSE` throughout |
| Result handling (`count()`, `first()`, `[0]`) | Compatible | All patterns valid |
| Bootstrap via `initialize()` | Compatible | Drupal integration fixes in 6.0/6.1 |
| Contact entity fields | **Fixed** | Core fields OK; address fields use `address_primary.*` join paths (#2) |
| Email entity fields | Compatible | `email`, `contact_id`, `is_primary` unchanged |
| UFMatch entity | Compatible | `uf_id`, `contact_id` unchanged |
| FinancialType entity | **Fixed** | Fields OK; `name` vs `label` fixed (#28) — uses `label` for display |
| Contribution entity fields | Compatible | All standard fields unchanged |
| Contribution statuses | Compatible | Completed, Cancelled, Pending valid |
| OptionValue entity | Compatible | `option_group_id:name` pseudoconstant valid |
| Membership entity fields | Compatible | All fields unchanged |
| Membership statuses | Compatible | New, Current, Grace, Cancelled, Pending valid |
| MembershipType entity | Compatible | `duration_unit`, `duration_interval`, `period_type` unchanged |
| MembershipStatus entity | Compatible | Standard query fields unchanged |
| System entity | Compatible | `System::get()` unchanged |
| Membership-to-Contribution linking | **Fixed** | Uses `\Civi\Api4\Order::create()` (#6) |
| GroupContact entity | Compatible | Used by MailingUpdater (#13) |\n| Custom field reference | **Fixed** | Uses `Commerce_Order.commerce_order_id` group.field syntax (#3) |

## Appendix C — MembershipUpdater API4 Migration Table

**All 13 calls migrated to OOP via prompt 05.** Uses `use Civi\Api4\{Membership, MembershipType, MembershipStatus}`.

~~13 calls to migrate from procedural `civicrm_api4()` to OOP `\Civi\Api4\Entity::action(FALSE)`.~~ Complete.

---

## Implementation Guidelines

### Development Standards

1. **API Usage**: All CiviCRM interactions must use API4 (OOP style preferred)
2. **Error Handling**: Catch specific exception types with detailed logging
3. **Testing**: Unit tests for all new functionality
4. **Documentation**: Update service documentation in `/doc/services/` for all changes
5. **Configuration**: Make features configurable where possible

### Testing Requirements

1. **Unit Tests**: Individual service methods
2. **Integration Tests**: Order workflow scenarios
3. **CiviCRM Tests**: Against an actual CiviCRM instance
4. **Edge Cases**: Error conditions and boundary cases

### Documentation Updates

When implementing items, update:
1. Service documentation in `/doc/services/`
2. User guides in `/doc/user-guide/`
3. This file — move completed items to the Completed section below
4. Add configuration documentation if needed

---

## Completed Items

### 1. Missing helper functions — fatal errors (Critical) ✓

*Completed 2026-02-10 via prompt 01.*

**What was done**:
- Added `getEvents()`, `getParticipantRoles()`, `getMailingGroups()` methods to `ContactUpdater.php` (L489, L524, L560)
- Added procedural wrappers `_commerce_civicrm_get_events()`, `_commerce_civicrm_get_participant_roles()`, `_commerce_civicrm_get_mailing_groups()` to `commerce_civicrm.module` (L393, L414, L435)
- All use OOP API4 style matching existing `getFinancialTypes()` / `getMembershipTypes()` patterns
- Product edit form no longer produces fatal errors for `event` or `mailing` entity types

### 2. Address fields written incorrectly to Contact (Critical) ✓

*Completed 2026-02-10 via prompt 02.*

**What was done**:
- `extractContactData()` now uses `address_primary.*` join paths for all address fields
- State uses `address_primary.state_province_id:abbr` pseudoconstant (was `state_province`)
- Country uses `address_primary.country_id:name` pseudoconstant (was `country`)
- `updateExistingContact()` strips `contact_type` alongside `email` before update
- Debug logging in `extractContactData()` changed from `print_r()` to `json_encode()`

**Side effects**: Partially addresses #14 (`print_r` removal — 1 of 3 call sites fixed).

### 3. `custom_commerce_order_id` silently ignored (Critical) ✓

*Completed 2026-02-10 via prompt 03.*

**What was done**:
- Created `Commerce_Order` custom group + `commerce_order_id` custom field provisioning in `commerce_civicrm.install` via `_commerce_civicrm_provision_custom_fields()` (called from `commerce_civicrm_install()`)
- Custom group cleanup added to `commerce_civicrm_uninstall()`
- `extractContributionData()` uses `Commerce_Order.commerce_order_id` (was `custom_commerce_order_id`)
- `createContributionFromOrderWithFinancialType()` sets `Commerce_Order.commerce_order_id` and uses standardized `source` format
- `findExistingContribution()` uses exact custom field lookup with exact `source` fallback + backfill (resolves #7)
- `ensureCustomFieldExists()` method added to `ContributionUpdater` for runtime provisioning fallback
- Date format in `createContributionFromOrderWithFinancialType()` standardized to `Y-m-d H:i:s` (was `YmdHis`)
- `source` format standardized to `'Commerce Order #' . $order->id()` across both creation methods

**Side effects**: Fully resolves #7 (fragile LIKE search). Partially addresses #24 (date format consistency)

### 7. Fragile contribution lookup (LIKE search) (High) ✓

*Completed 2026-02-10 — resolved as part of #3.*

`findExistingContribution()` now queries `Commerce_Order.commerce_order_id` with exact `=` match (primary), falling back to exact `source =` match (for legacy contributions), with automatic backfill of the custom field.

### 4. Order cancellation stubs (High) ✓

*Completed 2026-02-10 via prompt 04.*

**What was done**:
- `processCancellation()` added to `OrderCivicrmUpdater.php` (L684–L821)
- Iterates order items: calls `cancelMembershipFromOrder()` per membership item and `cancelContributionFromOrder()` once per order
- Each cancellation wrapped in independent try/catch — one failure doesn't block others
- `cancelMembershipFromOrder()` uses `Membership::update(FALSE)` to set status "Cancelled"
- `cancelContributionFromOrder()` uses `Contribution::update(FALSE)` to set status "Cancelled"
- Returns structured results array with per-item success/failure tracking

**Side effects**: Adds 9 OOP API4 calls to `OrderCivicrmUpdater` (was 0). Updates Appendix A.

### 5. MembershipUpdater procedural API4 → OOP (High) ✓

*Completed 2026-02-10 via prompt 05.*

**What was done**:
- All 13 `civicrm_api4()` calls migrated to OOP `\Civi\Api4\Entity::action(FALSE)` style
- Added `use Civi\Api4\{Membership, MembershipType, MembershipStatus}` imports
- No functional changes — purely calling-convention refactor
- All try/catch error handling preserved
- Appendix C fully resolved

### 6. No Contribution↔Membership LineItem linking (High) ✓

*Completed 2026-02-10 via prompt 06.*

**What was done**:
- `processLinkedMembershipContribution()` in `OrderCivicrmUpdater.php` uses `\Civi\Api4\Order::create(FALSE)` at L368 for atomic Contribution+Membership+LineItem creation
- CiviCRM automatically creates `LineItem` and `MembershipPayment` records linking the entities
- Reports, auto-renewal, and cancellation propagation now work correctly
- Adds `receive_date` via `date('Y-m-d H:i:s')` — adds one new `date()` call site (#24)

**Side effects**: Partially expands #24 (now 3 bare `date()` calls). Updates Appendix B compatibility matrix.

### 8. Contact dedup by first+last name only (High) ✓

*Completed 2026-02-10 via prompt 08.*

**What was done**:
- `findExistingContact()` now uses `Contact::getDuplicates(FALSE)` with `->setDedupeRule('Individual.Supervised')` at L244
- Leverages CiviCRM's built-in Supervised dedupe rule instead of naive name matching
- Ambiguous multi-match returns NULL with a warning log at L258
- Fallback query adds `contact_type = 'Individual'` at L273 and uses `setLimit(2)` to detect ambiguity
- Adds 1 new OOP API4 call to `ContactUpdater` (13 total, was 12)

### 9. Membership dates bypass CiviCRM native logic (High) ✓

*Completed 2026-02-10 via prompt 09.*

**What was done**:
- `calculateMembershipDates()` in `MembershipUpdater.php` L254–L261 simplified
- Returns only `join_date` and `start_date` — no longer calculates `end_date`
- Uses `\DateTimeImmutable` for date formatting (not bare `date()`)
- No hardcoded `12-31` — CiviCRM calculates `end_date` natively based on membership type period settings
- Rolling vs. fixed periods, rollover days, and grace periods now handled by CiviCRM

### 10. 350+ line procedural form alter (High) ✓

*Completed 2026-02-10 via prompt 10.*

**What was done**:
- Created `src/Service/ProductFormHelper.php` (327 lines) — new service class
- Extracted all form alter/submit logic from `commerce_civicrm.module` into `ProductFormHelper`
- `.module` reduced from ~586 lines to 241 lines — contains only thin hook stubs
- All seven `_commerce_civicrm_*` prefixed helper functions absorbed into service methods (#25 resolved)
- Registered in `commerce_civicrm.services.yml` as `commerce_civicrm.product_form_helper`
- Uses `StringTranslationTrait` for proper translation (#23 partially addressed)
- Injected dependencies: `@logger.factory`, `@commerce_civicrm.civicrm_helper`, `@commerce_civicrm.membership_updater`, `@commerce_civicrm.contact_updater`

**Side effects**: Fully resolves #25. Partially addresses #23 (ProductFormHelper uses `$this->t()`, but ContactUpdater/MembershipUpdater still use global `t()`).

### 11. Static `\Drupal::` calls in CivicrmHelper (High) ✓

*Completed 2026-02-10 via prompt 11.*

**What was done**:
- `CivicrmHelper` constructor now injects `ModuleHandlerInterface $module_handler` and `?object $civicrm = NULL`
- Zero `\Drupal::` static calls remain
- Uses `$this->moduleHandler->moduleExists('civicrm')` at L72 (was `\Drupal::moduleHandler()`)
- Uses `$this->civicrm->initialize()` at L82 (was `\Drupal::service('civicrm')`)
- `commerce_civicrm.services.yml` updated: CivicrmHelper args now `['@logger.factory', '@module_handler', '@?civicrm']`

### 12. Redundant `initializeCivicrm()` wrappers and calls (High) ✓

*Completed 2026-02-10 via prompt 12.*

**What was done**:
- Removed private `initializeCivicrm()` wrapper methods from `ContactUpdater` and `ContributionUpdater`
- All call sites now use `$this->civicrmHelper->initialize()` directly — consistent with `MembershipUpdater`
- Zero `initializeCivicrm` references remain in `src/`

### 25. `_` prefixed procedural functions (Low) ✓

*Completed 2026-02-10 — resolved as part of #10.*

All seven `_` prefixed functions removed from `commerce_civicrm.module` and absorbed into `ProductFormHelper` service methods:

- `_commerce_civicrm_is_available()` → `ProductFormHelper::isCivicrmAvailable()`
- `_commerce_civicrm_get_product_settings()` → `ProductFormHelper::getProductSettings()`
- `_commerce_civicrm_get_membership_types()` → delegates to `MembershipUpdater::getMembershipTypes()`
- `_commerce_civicrm_get_financial_types()` → delegates to `ContactUpdater::getFinancialTypes()`
- `_commerce_civicrm_get_events()` → delegates to `ContactUpdater::getEvents()`
- `_commerce_civicrm_get_participant_roles()` → delegates to `ContactUpdater::getParticipantRoles()`
- `_commerce_civicrm_get_mailing_groups()` → delegates to `ContactUpdater::getMailingGroups()`

### 13. Mailing list stubs (double opt-in / welcome) (Medium) ✓

*Completed 2026-02-10 via prompt 13.*

**What was done**:
- Created `src/Service/MailingUpdater.php` (302 lines) — new service class
- Core methods fully functional: `addContactToMailingGroup()`, `removeContactFromMailingGroup()`, `processMailingSubscriptionFromOrder()`, `checkGroupMembership()`
- Uses `\Civi\Api4\GroupContact::create(FALSE)`, `::update(FALSE)`, `::get(FALSE)` — 4 OOP API4 calls
- `sendDoubleOptInEmail()` and `sendWelcomeMessage()` remain as stubs with `@todo` and logger info
- Handles existing memberships, double opt-in (`status = 'Pending'`), and welcome message preferences
- Registered in `commerce_civicrm.services.yml` as `commerce_civicrm.mailing_updater`
- Wired into `OrderCivicrmUpdater` — `processOrderItem()` handles `mailing` entity type at L318
- Typed properties and return types from the start (follows #15, #16)
- Uses `\CRM_Core_Exception` catches (follows #21)

### 14. `print_r()` in production logging (Medium) ✓

*Completed 2026-02-10 via prompt 14.*

**What was done**:
- Replaced 2 remaining `print_r($contact_data, TRUE)` calls with `json_encode($contact_data)` in `ContactUpdater.php`
- Zero `print_r()` calls remain in `src/`

### 15. No PHP return-type declarations (Medium) ✓

*Completed 2026-02-10 via prompt 15.*

**What was done**:
- Added return types to all public, protected, and private methods across all 8 `src/` classes
- All methods (except constructors) now have explicit return types: `: bool`, `: ?int`, `: array`, `: void`, `: ?array`, `: static`, `: string`, etc.
- `@return` PHPDoc annotations updated to match declared types
- Verification: `grep -rn 'function ' src/ | grep -v '__construct' | grep -v ': '` returns zero results

### 16. No typed properties (Medium) ✓

*Completed 2026-02-10 via prompt 16.*

**What was done**:
- Added PHP type declarations to all properties in all 8 `src/` classes
- Types match the existing `@var` PHPDoc annotations: `EntityTypeManagerInterface`, `LoggerChannelInterface`, `CivicrmHelper`, `ContactUpdater`, etc.
- `CivicrmHelper`: `?object $civicrm` typed correctly for nullable CiviCRM service
- All `use` statements present for referenced types
- Verification: `grep -rn 'protected \$\|public \$\|private \$' src/ | grep -v ': '` returns zero results

**Side effects**: Resolves #26 — unused `ProductInterface` import removed from ContactUpdater and MembershipUpdater, unused `PaymentInterface` import removed from ContributionUpdater (kept only as FQCN in `@var` docblock at L364).

### 17. Dead `ContainerInjectionInterface` on subscriber (Medium) ✓

*Completed 2026-02-10 via prompt 17.*

**What was done**:
- Removed `use Symfony\Component\DependencyInjection\ContainerInterface;`
- Removed `use Drupal\Core\DependencyInjection\ContainerInjectionInterface;`
- Removed `ContainerInjectionInterface` from `implements` clause — now `implements EventSubscriberInterface` only
- Removed dead `create()` factory method
- Constructor and all event handler methods preserved
- `OrderCompleteSubscriber.php` reduced from ~231 to 229 lines

### 18. Deprecated `REQUIREMENT_*` constants (Medium) ✓

*Completed 2026-02-10 via prompt 18.*

**What was done**:
- Added `use Drupal\Core\Extension\Requirement\RequirementSeverity;` at top of `commerce_civicrm.install`
- Replaced 3× `REQUIREMENT_ERROR` → `RequirementSeverity::Error`
- Replaced 1× `REQUIREMENT_OK` → `RequirementSeverity::OK`
- Replaced 1× `REQUIREMENT_WARNING` → `RequirementSeverity::Warning`
- Zero `REQUIREMENT_` constants remain in the file

### 19. `hook_requirements()` to `hook_runtime_requirements()` (Medium) ✓

*Completed 2026-02-10 via prompt 19.*

**What was done**:
- Renamed `commerce_civicrm_requirements($phase)` → `commerce_civicrm_runtime_requirements()` (no parameters)
- Updated PHPDoc to `Implements hook_runtime_requirements().`
- Removed `if ($phase === 'runtime')` wrapper and un-indented the body
- Zero references to `$phase` remain

### 20. `composer.json` inconsistencies (Medium) ✓

*Completed 2026-02-10 via prompt 20.*

**What was done**:
- `authors[0].name` → `"Commerce CiviCRM Contributors"` (was `"Your Name"`)
- `authors[0].email` field removed (was placeholder `"your.email@example.com"`)
- `authors[0].homepage` → `"https://www.drupal.org/project/commerce_civicrm"`
- `keywords` trimmed from 11 to 7 entries — removed `ecommerce`, `automation`, `workflow`, `event`
- JSON validates correctly

**Note**: `description`, `require.drupal/commerce`, `require.drupal/rules`, `require-dev.phpunit/phpunit` were already fixed by earlier prompts.

### 21. Generic exception catching (Medium) ✓

*Completed 2026-02-10 via prompt 21.*

**What was done**:
- Replaced `catch (\Exception $e)` with `catch (\CRM_Core_Exception $e)` across all service classes and `commerce_civicrm.install`
- 50 total `\CRM_Core_Exception` catches across `src/`
- `CivicrmHelper::initialize()` intentionally retains `catch (\Exception $e)` with an explanatory comment — CiviCRM initialization can throw various exception types depending on installation state
- No functional changes — all log messages and return values preserved

### 22. Views config D7-style (Medium) ✓

*Completed 2026-02-10 via prompt 22.*

**What was done**:
- Renamed `config/install/views.view.my-orders.yml` → `config/install/views.view.commerce_civicrm_my_orders.yml`
- View ID changed from `my-orders` to `commerce_civicrm_my_orders`
- Removed all `entity_type: null` lines (D7 artifacts)
- Access control changed from `type: none` to `type: perm` with `perm: 'view own commerce_order'`
- Updated `commerce_civicrm.info.yml` config reference
- Updated `commerce_civicrm.install` uninstall config reference
- Old `views.view.my-orders.yml` file deleted

### 23. `t()` function in service classes (Medium) ✓

*Completed 2026-02-10 via prompt 23.*

**What was done**:
- Added `use Drupal\Core\StringTranslation\StringTranslationTrait;` and `use StringTranslationTrait;` to `ContactUpdater` and `MembershipUpdater`
- Replaced all 12 bare `t()` calls in `ContactUpdater` with `$this->t()`
- Replaced all 2 bare `t()` calls in `MembershipUpdater` with `$this->t()`
- `ProductFormHelper` already used `StringTranslationTrait` (from prompt #10)
- Zero bare `t()` calls remain in `src/`

### 24. Inconsistent date formatting (Medium) ✓

*Completed 2026-02-10 via prompt 24.*

**What was done**:
- Added `use Drupal\Core\Datetime\DrupalDateTime;` to `ContributionUpdater.php` and `OrderCivicrmUpdater.php`
- Replaced `date('Y-m-d H:i:s', ...)` with `DrupalDateTime::createFromTimestamp(...)->format('Y-m-d H:i:s')` (2 sites in ContributionUpdater, 1 in OrderCivicrmUpdater)
- Replaced `time()` with `\Drupal::time()->getRequestTime()` where applicable
- Zero bare `date()` calls remain in `src/`
- Format string `Y-m-d H:i:s` preserved for CiviCRM compatibility

### 26. Unused `use` imports (Low) ✓

*Completed 2026-02-10 — resolved as part of #16.*

- `ProductInterface` removed from `ContactUpdater.php` and `MembershipUpdater.php`
- `PaymentInterface` removed from `ContributionUpdater.php` import line (retained only as FQCN in `@var` docblock at L364)
- Zero unused `use` imports remain in `src/`

### 27. Constructor property promotion not used (Low) ✓

*Completed 2026-02-10 via prompt 27.*

**What was done**:
- Refactored all 8 `src/` constructors to use PHP 8.0+ constructor property promotion
- All directly-stored dependencies use `protected readonly` in constructor signature
- Only `$logger` remains non-promoted (derived from `LoggerChannelFactoryInterface` via `->get('commerce_civicrm')`)
- Removed separate property declarations and `$this->property = $argument;` assignments for all promoted properties
- Removed `@var` PHPDoc blocks for promoted properties
- Files modified: `CivicrmHelper.php`, `ContactUpdater.php`, `ContributionUpdater.php`, `MembershipUpdater.php`, `MailingUpdater.php`, `OrderCivicrmUpdater.php`, `ProductFormHelper.php`, `OrderCompleteSubscriber.php`

### 28. FinancialType `name` vs `label` display (Low) ✓

*Completed 2026-02-10 via prompt 28.*

**What was done**:
- `getFinancialTypes()` in `ContactUpdater.php` now uses `$type['label']` for display (was `$type['name']`)
- Added `->addSelect('id', 'name', 'label')` to explicitly request needed fields
- Changed `->addOrderBy('name', 'ASC')` to `->addOrderBy('label', 'ASC')` for correct sort order
- `getMembershipTypes()` in `MembershipUpdater.php` also updated: uses `$type['label']`, `->addSelect('id', 'name', 'label', 'description')`, sorts by `label`
- Multi-language CiviCRM installations now see translated labels in product forms

### 29. No CiviCRM maintenance mode awareness (Low) ✓

*Completed 2026-02-10 via prompt 29.*

**What was done**:
- Added `isInMaintenanceMode()` protected method to `CivicrmHelper.php`
- Checks `CIVICRM_UPGRADE_ACTIVE` constant (CiviCRM 4.x+) and `\Civi::settings()->get('environment') === 'Maintenance'` (CiviCRM 6.1+)
- `initialize()` now returns FALSE with warning log when maintenance mode detected
- Added `isReadyForOperations()` public method — stronger check than `isAvailable()` for write operations
- `isInMaintenanceMode()` wrapped in try/catch — never throws, returns FALSE on error
- `isAvailable()` unchanged for backward compatibility
- All services calling `$this->civicrmHelper->initialize()` are automatically protected

### 30. `composer.json` CiviCRM version constraint too broad (Low) ✓

*Completed 2026-02-10 via prompt 30.*

**What was done**:
- Changed `drupal/civicrm` constraint from `^6.0` to `^6.1` (minimum for maintenance mode support via #29)
- Added `"tested-civicrm-versions": "6.1.0 — 6.2.0"` to `extra.drupal` section
- JSON validated successfully

---

## References

### CiviCRM API4
- [API4 Documentation](https://docs.civicrm.org/dev/en/latest/api/v4/)
- [API4 Explorer](https://your-civicrm-site/civicrm/api4#/explorer)
- [API4 Examples](https://docs.civicrm.org/dev/en/latest/api/v4/examples/)

### Drupal Commerce
- [Order Events](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-events)
- [State Machine Workflows](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-workflows)

### Module Patterns
- Follow existing service patterns in the module
- Use the logger factory consistently
- Maintain result array structures for consistency
- Follow defensive programming practices (especially for CiviCRM connectivity)

### Priority Assessment Criteria

| Priority | Meaning |
|----------|---------|
| **Critical** | Runtime errors, data loss, installation failures |
| **High** | Core functionality gaps, data integrity, architecture |
| **Medium** | Code quality, modernization, D11 conventions |
| **Low** | Style conventions, minor improvements |
| **Future** | New feature ideas, enhancements |

Items can be re-prioritised based on user feedback, community contributions, integration requirements, and performance considerations.
