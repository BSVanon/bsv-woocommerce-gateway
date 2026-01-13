# BSV WooCommerce Plugin - Complete Recovery & Testing Plan

## Current Situation
- Original codebase deleted from `/home/robert/Documents/BSV-woocommerce`
- Recovered code at `/home/robert/Documents/BSV-woocommerce-recovered` (git clone)
- Docker containers pointing to deleted directory (broken)
- 3 incomplete submissions to WordPress.org
- Plugin has critical bug fixes (sessionStorage, createStepper) that MUST be preserved

## PHASE 1: Reorganize File Structure ✓
**Goal:** Clean up directory structure and establish working directory

1. Stop broken Docker containers
2. Create symlink: `/home/robert/Documents/BSV-woocommerce` → `/home/robert/Documents/BSV-woocommerce-recovered`
3. This preserves Docker mount paths without moving files

## PHASE 2: Restore Docker Environment
**Goal:** Get WordPress + WooCommerce + plugin-check working

1. Start Docker containers (they'll now see recovered code via symlink)
2. Verify WordPress is accessible at http://localhost:8081
3. Verify WooCommerce is installed and activated
4. Install plugin-check plugin in Docker

## PHASE 3: Safe Plugin Installation & Testing
**Goal:** Test plugin WITHOUT risking codebase deletion

**CRITICAL SAFETY RULE:** Never bind-mount during plugin-check operations

1. Create clean zip from recovered code
2. Copy zip INTO Docker container: `docker cp plugin.zip container:/tmp/`
3. Install from zip inside container: `wp plugin install /tmp/plugin.zip --activate`
4. Run plugin-check: `wp plugin check bitcoin-sv-payments-for-woocommerce`
5. Export results to host

## PHASE 4: Fix All Errors Systematically
**Goal:** Address every error from plugin-check report

1. Review full plugin-check output
2. Categorize errors:
   - CRITICAL: i18n, security, escaping (must fix)
   - MEDIUM: deprecated functions, best practices (should fix)
   - LOW: third-party libs, acceptable warnings (document/ignore)
3. Fix errors in recovered codebase
4. Commit each fix to git with clear message
5. Repeat Phase 3 until clean

## PHASE 5: Functional Testing in Localhost
**Goal:** Verify plugin works correctly

1. Create test order in WooCommerce
2. Verify payment console displays correctly
3. Verify stepper UI appears (dynamic creation fix)
4. Verify no infinite page reload (sessionStorage fix)
5. Verify polling stops on completion
6. Test with real blockchain transaction if possible

## PHASE 6: Final Verification & Submission
**Goal:** Create verified, tested submission

1. Run final plugin-check (must be clean)
2. Create final zip with version number
3. Push to git with tag
4. Document all testing performed
5. Submit to WordPress.org with confidence

## PHASE 7: Document Everything
**Goal:** Prevent future issues

1. Create DOCKER-SAFETY.md with rules
2. Document testing procedure
3. Save plugin-check results
4. Update README with submission status

## Safety Protocols
- ✅ NEVER run `wp plugin delete` on bind-mounted directory
- ✅ NEVER run `wp plugin install --force` on bind-mounted directory
- ✅ ALWAYS copy zip into container for testing
- ✅ ALWAYS commit changes before Docker operations
- ✅ ALWAYS verify git status before risky operations

## Current Status
- Phase 1: IN PROGRESS
- Stopped broken Docker containers
- Ready to create symlink and proceed
