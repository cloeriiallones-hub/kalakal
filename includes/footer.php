</main>
<?php if ($navUser): ?></div><?php endif; ?>
<footer class="footer">
    <div class="container footer-inner">
        <a class="footer-brand" href="<?= e(url('index.php')) ?>">KALAKAL</a>
        <span>Collect. Valuate. Trade. &nbsp;·&nbsp; Municipality of Kawayan, Biliran</span>
        <span>&copy; <?= date('Y') ?> KALAKAL</span>
    </div>
</footer>
<script>
document.querySelectorAll('.password-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const password = document.getElementById(button.getAttribute('aria-controls'));
        const isVisible = password.type === 'text';
        password.type = isVisible ? 'password' : 'text';
        button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
        button.setAttribute('aria-pressed', String(!isVisible));
    });
});
document.querySelectorAll('[data-toggle-account-deletion]').forEach((button) => {
    button.addEventListener('click', () => {
        const details = document.getElementById(button.getAttribute('aria-controls'));
        const isExpanded = button.getAttribute('aria-expanded') === 'true';
        details.hidden = isExpanded;
        button.setAttribute('aria-expanded', String(!isExpanded));
        if (!isExpanded) {
            const password = details.querySelector('input[type="password"]');
            if (password) password.focus();
        }
    });
});
document.querySelectorAll('[data-confirm-account-deletion]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Delete your account now? Your account will be deactivated and unfinished requests and trades will be cancelled. You can restore it by logging in within 24 hours.')) {
            event.preventDefault();
        }
    });
});
document.querySelectorAll('[data-confirm-account-suspension]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Suspend this account and revoke all active sessions?')) {
            event.preventDefault();
        }
    });
});
document.querySelectorAll('[data-confirm-account-reactivation]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Reactivate this account?')) {
            event.preventDefault();
        }
    });
});
const selectInputTextOnClick = (container) => {
    if (!container) return;
    container.addEventListener('click', (event) => {
        const input = event.target.closest('input[type="text"], input[type="email"], input[type="tel"], input[type="password"]');
        if (!input || input.disabled || input.readOnly || input.value === '') return;
        input.select();
    });
};
selectInputTextOnClick(document.querySelector('.login-form'));
const registerForm = document.querySelector('.register-form');
if (registerForm) {
    selectInputTextOnClick(registerForm);
    selectInputTextOnClick(document.querySelector('.address-dialog'));
    const steps = [...registerForm.querySelectorAll('.register-step')];
    const setRegisterStep = (stepNumber) => {
        steps.forEach((step) => {
            step.hidden = Number(step.dataset.step) !== stepNumber;
        });
        registerForm.dataset.activeStep = String(stepNumber);
        const firstInput = steps[stepNumber - 1].querySelector('input:not([type="hidden"]), select, button');
        if (firstInput) firstInput.focus();
    };
    registerForm.querySelectorAll('[data-next-step]').forEach((button) => {
        button.addEventListener('click', () => {
            const currentStep = button.closest('.register-step');
            registerForm.dataset.validated = 'true';
            const invalidInput = currentStep.querySelector(':invalid');
            if (invalidInput) {
                invalidInput.reportValidity();
                return;
            }
            setRegisterStep(Number(currentStep.dataset.step) + 1);
        });
    });
    registerForm.querySelectorAll('[data-previous-step]').forEach((button) => {
        button.addEventListener('click', () => {
            setRegisterStep(Number(button.closest('.register-step').dataset.step) - 1);
        });
    });
    setRegisterStep(Number(registerForm.dataset.activeStep) || 1);

    const roleSelect = registerForm.querySelector('[name="user_role"]');
    const junkshopNameWrap = registerForm.querySelector('[data-junkshop-name-wrap]');
    const junkshopNameInput = junkshopNameWrap.querySelector('input');
    const registrationAddress = registerForm.querySelector('[data-registration-address]');
    const addressPicker = registerForm.querySelector('.address-picker');
    const addressDialog = document.getElementById('address-dialog');
    const addressFields = [...addressDialog.querySelectorAll('[data-address-field]')];
    const fullAddressFields = [...addressDialog.querySelectorAll('[data-full-address-field]')];
    const addressDialogTitle = addressDialog.querySelector('#address-dialog-title');
    const addressDialogCopy = addressDialog.querySelector('[data-address-dialog-copy]');
    const addressHiddenFields = Object.fromEntries(
        ['region', 'city', 'province', 'barangay', 'address_line', 'postal_code'].map((name) => [
            name,
            registerForm.querySelector(`[name="${name}"]`)
        ])
    );
    const addressLabel = registerForm.querySelector('[data-address-label]');
    const addressRequiredNote = addressPicker.querySelector('.address-required-note');
    const addressAction = addressPicker.querySelector('[data-address-action]');
    let addressSummary = addressPicker.querySelector('.address-summary');
    const sellerTypeWrap = registerForm.querySelector('[data-seller-type-wrap]');
    const sellerTypeSelect = sellerTypeWrap.querySelector('select');
    const requiresAddress = () => roleSelect.value === 'seller';
    const updateJunkshopField = () => {
        const isBuyer = roleSelect.value === 'buyer';
        junkshopNameWrap.hidden = !isBuyer;
        junkshopNameInput.disabled = !isBuyer;
        junkshopNameInput.required = isBuyer;
        const isSeller = roleSelect.value === 'seller';
        sellerTypeWrap.hidden = !isSeller;
        sellerTypeSelect.disabled = !isSeller;
        sellerTypeSelect.required = isSeller;
    };
    const updateAddressRequirement = () => {
        const needsFullAddress = requiresAddress();
        registrationAddress.hidden = false;
        addressPicker.setAttribute('aria-required', 'true');
        addressLabel.textContent = needsFullAddress ? 'Address' : 'Location';
        addressRequiredNote.textContent = needsFullAddress
            ? 'Required for pickups'
            : 'Required for nearby matches';
        addressAction.firstChild.textContent = needsFullAddress ? 'Add address ' : 'Add location ';
        addressDialogTitle.textContent = needsFullAddress ? 'Pickup address' : 'Business / service location';
        addressDialogCopy.textContent = needsFullAddress
            ? 'Required for collection pickups.'
            : (roleSelect.value === 'buyer'
                ? 'Select the barangay and city/municipality where your junkshop is located.'
                : 'Select the barangay and city/municipality where you live or operate.');
        fullAddressFields.forEach((label) => {
            label.hidden = !needsFullAddress;
            label.querySelector('input').disabled = !needsFullAddress;
            label.querySelector('input').required = needsFullAddress;
        });
        if (!needsFullAddress) {
            addressHiddenFields.address_line.value = '';
            addressHiddenFields.postal_code.value = '';
            addressFields.forEach((field) => {
                if (['address_line', 'postal_code'].includes(field.dataset.addressField)) field.value = '';
            });
        }
        if (addressSummary) {
            addressSummary.textContent = [
                ...(needsFullAddress ? [addressHiddenFields.address_line.value] : []),
                addressHiddenFields.barangay.value,
                addressHiddenFields.city.value,
                ...(needsFullAddress ? [addressHiddenFields.postal_code.value] : [])
            ].filter(Boolean).join(', ');
        }
    };
    roleSelect.addEventListener('change', updateJunkshopField);
    roleSelect.addEventListener('change', updateAddressRequirement);
    updateJunkshopField();
    updateAddressRequirement();

    const passwordInput = registerForm.querySelector('[name="password"]');
    const confirmPasswordInput = registerForm.querySelector('[name="confirm_password"]');
    const confirmPasswordField = confirmPasswordInput.closest('.password-field');
    const confirmPasswordFeedback = registerForm.querySelector('#confirm-password-feedback');
    const updatePasswordMatch = () => {
        const hasConfirmation = confirmPasswordInput.value.length > 0;
        const matches = hasConfirmation && passwordInput.value === confirmPasswordInput.value;
        confirmPasswordField.dataset.match = hasConfirmation ? String(matches) : '';
        confirmPasswordInput.setAttribute('aria-invalid', hasConfirmation && !matches ? 'true' : 'false');
        confirmPasswordFeedback.hidden = !hasConfirmation;
        confirmPasswordFeedback.textContent = hasConfirmation
            ? (matches ? 'Passwords match.' : 'Passwords do not match.')
            : '';
    };
    passwordInput.addEventListener('input', updatePasswordMatch);
    confirmPasswordInput.addEventListener('input', updatePasswordMatch);
    updatePasswordMatch();

    registerForm.querySelectorAll('[data-material-estimate]').forEach((material) => {
        const method = material.querySelector('[data-estimate-method]');
        const quantityLabel = material.querySelector('[data-estimate-quantity-label]');
        const quantity = material.querySelector('[data-estimate-quantity]');
        const sizeWrap = material.querySelector('[data-estimate-size-wrap]');
        const size = material.querySelector('[data-estimate-size]');
        const updateEstimateFields = () => {
            const isWeight = method.value === 'weight';
            const isSize = method.value === 'size';
            quantityLabel.firstChild.textContent = isWeight
                ? 'Estimated weight (kg)'
                : (method.value === 'pieces' ? 'Number of pieces' : 'Number of items');
            quantity.placeholder = isWeight ? 'e.g. 5 kg' : 'e.g. 10';
            sizeWrap.hidden = !isSize;
            size.disabled = !isSize;
            size.required = isSize;
            if (!isSize) size.value = '';
        };
        method.addEventListener('change', updateEstimateFields);
        updateEstimateFields();
    });

    const phoneInput = registerForm.querySelector('[name="phone_number"]');
    phoneInput.addEventListener('input', () => {
        phoneInput.value = phoneInput.value.replace(/\D/g, '').replace(/^[^9]+/, '').slice(0, 10);
    });

    const regionField = addressFields.find((field) => field.dataset.addressField === 'region');
    const cityField = addressFields.find((field) => field.dataset.addressField === 'city');
    const barangayField = addressFields.find((field) => field.dataset.addressField === 'barangay');
    const locationError = addressDialog.querySelector('.location-load-error');
    const locationApi = 'https://psgc.gitlab.io/api';
    const cityCache = new Map();
    const barangayCache = new Map();
    let cityLoadSequence = 0;
    let barangayLoadSequence = 0;

    const setSelectOptions = (select, placeholder, rows) => {
        select.replaceChildren(new Option(placeholder, '', true, true));
        select.options[0].disabled = true;
        rows.forEach((row) => {
            const option = new Option(row.name, row.name);
            option.dataset.code = row.code;
            if (row.province) option.dataset.province = row.province;
            select.add(option);
        });
        select.disabled = rows.length === 0;
    };
    const loadJsonList = async (path) => {
        const response = await fetch(`${locationApi}/${path}`);
        if (!response.ok) throw new Error(`PSGC returned ${response.status} for ${path}.`);
        const rows = await response.json();
        if (!Array.isArray(rows)) throw new Error(`PSGC returned invalid data for ${path}.`);
        return rows;
    };
    const showLocationError = (error) => {
        console.error(error);
        locationError.textContent = 'Could not load locations. Check your internet connection and try again.';
        locationError.hidden = false;
    };
    const loadBarangays = async (savedBarangay = '') => {
        const requestSequence = ++barangayLoadSequence;
        const cityCode = cityField.selectedOptions[0]?.dataset.code;
        setSelectOptions(barangayField, 'Loading barangays…', []);
        barangayField.disabled = true;
        locationError.hidden = true;
        if (!cityCode) {
            setSelectOptions(barangayField, 'Select a city / municipality first', []);
            return;
        }

        try {
            let rows = barangayCache.get(cityCode);
            if (!rows) {
                rows = (await loadJsonList(`cities-municipalities/${cityCode}/barangays/`))
                    .map((item) => ({ code: String(item.code), name: String(item.name) }))
                    .sort((a, b) => a.name.localeCompare(b.name));
                barangayCache.set(cityCode, rows);
            }
            if (requestSequence !== barangayLoadSequence) return;
            if (rows.length === 0) throw new Error(`PSGC returned no barangays for ${cityCode}.`);
            setSelectOptions(barangayField, 'Select your barangay', rows);
            if (savedBarangay) barangayField.value = savedBarangay;
        } catch (error) {
            if (requestSequence !== barangayLoadSequence) return;
            setSelectOptions(barangayField, 'Barangays unavailable', []);
            showLocationError(error);
        }
    };
    const loadCities = async (regionName, savedCity = '', savedBarangay = '') => {
        const requestSequence = ++cityLoadSequence;
        setSelectOptions(cityField, 'Loading cities / municipalities…', []);
        setSelectOptions(barangayField, 'Select a city / municipality first', []);
        cityField.disabled = true;
        barangayField.disabled = true;
        locationError.hidden = true;
        const selectedRegion = [...regionField.options].find((option) => option.value === regionName);
        const regionCode = selectedRegion?.dataset.regionCode;
        if (!regionCode) {
            setSelectOptions(cityField, 'Select a region first', []);
            return;
        }

        try {
            let rows = cityCache.get(regionCode);
            if (!rows) {
                if (regionCode === '180000000') {
                    const sources = await Promise.all(['060000000', '070000000'].map(async (sourceCode) => {
                        const [provinces, cities] = await Promise.all([
                            loadJsonList(`regions/${sourceCode}/provinces/`),
                            loadJsonList(`regions/${sourceCode}/cities-municipalities/`),
                        ]);
                        const provinceNames = new Map(provinces.map((province) => [String(province.code), String(province.name)]));
                        return cities
                            .filter((city) => ['Negros Occidental', 'Negros Oriental', 'Siquijor'].includes(
                                provinceNames.get(String(city.provinceCode))
                            ))
                            .map((city) => ({
                                code: String(city.code),
                                name: String(city.name),
                                province: provinceNames.get(String(city.provinceCode)) || '',
                            }));
                    }));
                    rows = sources.flat();
                } else {
                    const [provinces, cities] = await Promise.all([
                        loadJsonList(`regions/${regionCode}/provinces/`),
                        loadJsonList(`regions/${regionCode}/cities-municipalities/`),
                    ]);
                    const provinceNames = new Map(provinces.map((province) => [String(province.code), String(province.name)]));
                    rows = cities.map((city) => ({
                        code: String(city.code),
                        name: String(city.name),
                        province: provinceNames.get(String(city.provinceCode)) || '',
                    }));
                }
                rows.sort((a, b) => a.name.localeCompare(b.name));
                cityCache.set(regionCode, rows);
            }
            if (requestSequence !== cityLoadSequence) return;
            if (rows.length === 0) throw new Error(`PSGC returned no cities or municipalities for ${regionCode}.`);
            setSelectOptions(cityField, 'Select a city / municipality', rows);
            if (savedCity) {
                cityField.value = savedCity;
                if (cityField.value) await loadBarangays(savedBarangay);
            }
        } catch (error) {
            if (requestSequence !== cityLoadSequence) return;
            setSelectOptions(cityField, 'Cities / municipalities unavailable', []);
            setSelectOptions(barangayField, 'Select a city / municipality first', []);
            showLocationError(error);
        }
    };

    regionField.addEventListener('change', () => {
        loadCities(regionField.value);
    });
    cityField.addEventListener('change', () => {
        loadBarangays();
    });
    addressPicker.addEventListener('click', async () => {
        addressDialog.showModal();
        addressFields.forEach((field) => {
            if (field.dataset.addressField !== 'region'
                && field.dataset.addressField !== 'city'
                && field.dataset.addressField !== 'barangay') {
                field.value = addressHiddenFields[field.dataset.addressField].value;
            }
        });
        regionField.value = addressHiddenFields.region.value;
        if (regionField.value) {
            await loadCities(
                regionField.value,
                addressHiddenFields.city.value,
                addressHiddenFields.barangay.value
            );
        } else {
            setSelectOptions(cityField, 'Select a region first', []);
            setSelectOptions(barangayField, 'Select a city / municipality first', []);
        }
    });
    addressDialog.querySelectorAll('[data-close-address]').forEach((button) => {
        button.addEventListener('click', () => addressDialog.close());
    });
    addressDialog.addEventListener('click', (event) => {
        if (event.target === addressDialog) addressDialog.close();
    });
    addressDialog.querySelector('[data-save-address]').addEventListener('click', () => {
        addressDialog.dataset.validated = 'true';
        const invalidField = addressFields.find((field) => !field.disabled && !field.checkValidity());
        if (invalidField) {
            invalidField.reportValidity();
            return;
        }
        addressFields.forEach((field) => {
            addressHiddenFields[field.dataset.addressField].value = field.value.trim();
        });
        addressHiddenFields.province.value = cityField.selectedOptions[0]?.dataset.province || '';
        if (!addressSummary) {
            addressSummary = document.createElement('span');
            addressSummary.className = 'address-summary';
            addressPicker.querySelector('.address-picker-copy').append(addressSummary);
        }
        addressSummary.textContent = [
            ...(requiresAddress() ? [addressHiddenFields.address_line.value] : []),
            addressHiddenFields.barangay.value,
            addressHiddenFields.city.value,
            ...(requiresAddress() ? [addressHiddenFields.postal_code.value] : [])
        ].filter(Boolean).join(', ');
        addressPicker.dataset.complete = 'true';
        delete addressPicker.dataset.invalid;
        addressDialog.close();
    });
    registerForm.addEventListener('submit', (event) => {
        const requiredLocationFields = ['region', 'city', 'barangay'];
        if (requiresAddress()) requiredLocationFields.push('address_line', 'postal_code');
        const missingLocation = requiredLocationFields.find((name) => !addressHiddenFields[name].value.trim());
        if (missingLocation) {
            event.preventDefault();
            addressPicker.dataset.invalid = 'true';
            addressDialog.dataset.validated = 'true';
            addressDialog.showModal();
            const firstMissingField = addressFields.find((field) => field.dataset.addressField === missingLocation);
            if (firstMissingField) firstMissingField.focus();
        }
    });
}
document.querySelectorAll('[data-request-form] [data-material-estimate]').forEach((material) => {
    const pick = material.querySelector('[data-estimate-select]');
    const fields = material.querySelector('[data-estimate-fields]');
    if (!pick || !fields) return;
    const update = () => {
        const on = pick.checked;
        fields.hidden = !on;
        material.classList.toggle('is-selected', on);
        fields.querySelectorAll('input, select').forEach((input) => {
            input.disabled = !on;
            input.required = on;
        });
    };
    pick.addEventListener('change', update);
    update();
});
const onboardingPopup = document.getElementById('onboarding-popup');
if (onboardingPopup) {
    const dismissKey = 'kalakal-onboarding-dismissed-' + onboardingPopup.dataset.user;
    let dismissed = false;
    try { dismissed = sessionStorage.getItem(dismissKey) === '1'; } catch (error) { dismissed = false; }
    onboardingPopup.hidden = dismissed;
    const later = onboardingPopup.querySelector('[data-onboarding-later]');
    if (later) {
        later.addEventListener('click', () => {
            onboardingPopup.hidden = true;
            try { sessionStorage.setItem(dismissKey, '1'); } catch (error) { /* ignore */ }
        });
    }
}
const navToggle = document.querySelector('.nav-toggle');
if (navToggle) {
    const publicNav = document.getElementById(navToggle.getAttribute('aria-controls'));
    const setPublicNavExpanded = (expanded) => {
        navToggle.setAttribute('aria-expanded', String(expanded));
        navToggle.setAttribute('aria-label', expanded ? 'Close navigation menu' : 'Open navigation menu');
        publicNav.classList.toggle('is-open', expanded);
    };
    navToggle.addEventListener('click', () => {
        const expanded = navToggle.getAttribute('aria-expanded') === 'true';
        setPublicNavExpanded(!expanded);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
            setPublicNavExpanded(false);
            navToggle.focus();
        }
    });
}
const workspaceLayout = document.getElementById('workspace-layout');
const workspaceSidebar = document.getElementById('workspace-sidebar');
const workspaceSidebarToggles = [...document.querySelectorAll('[data-sidebar-toggle]')];
if (workspaceLayout && workspaceSidebar && workspaceSidebarToggles.length) {
    const preferenceKey = 'kalakal-workspace-sidebar-collapsed';
    const setWorkspaceSidebarCollapsed = (collapsed) => {
        workspaceLayout.classList.toggle('sidebar-collapsed', collapsed);
        document.body.classList.toggle('workspace-sidebar-collapsed', collapsed);
        workspaceSidebarToggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.setAttribute('aria-label', collapsed ? 'Show workspace menu' : 'Hide workspace menu');
            toggle.title = collapsed ? 'Show workspace menu' : 'Hide workspace menu';
        });
        workspaceSidebar.setAttribute('aria-hidden', String(collapsed));
        workspaceSidebar.inert = collapsed;
    };
    setWorkspaceSidebarCollapsed(localStorage.getItem(preferenceKey) === 'true');
    workspaceSidebarToggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const collapsed = !workspaceLayout.classList.contains('sidebar-collapsed');
            setWorkspaceSidebarCollapsed(collapsed);
            localStorage.setItem(preferenceKey, String(collapsed));
        });
    });
}
const sidebarAccount = document.querySelector('.sidebar-account');
if (sidebarAccount) {
    document.addEventListener('click', (event) => {
        if (sidebarAccount.open && !sidebarAccount.contains(event.target)) {
            sidebarAccount.open = false;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebarAccount.open) {
            sidebarAccount.open = false;
            sidebarAccount.querySelector('summary').focus();
        }
    });
}
const offerSelect = document.getElementById('estimate-offer');
const estimateQuantity = document.getElementById('estimate-quantity');
const estimateUnit = document.getElementById('estimate-unit');
const estimateOutput = document.getElementById('estimate-value');
if (offerSelect && estimateQuantity && estimateOutput) {
    const updateEstimate = () => {
        const selected = offerSelect.options[offerSelect.selectedIndex];
        const price = Number(selected?.dataset.price);
        const quantity = Number(estimateQuantity.value);
        const hasOffer = Boolean(selected && selected.value);
        const value = hasOffer && Number.isFinite(quantity) && quantity > 0 ? price * quantity : 0;
        estimateQuantity.disabled = !hasOffer;
        estimateQuantity.placeholder = hasOffer ? 'Enter quantity' : 'Select an offer first';
        estimateUnit.textContent = hasOffer ? `Quantity in ${selected.dataset.unit}` : '';
        estimateOutput.textContent = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(value);
    };
    offerSelect.addEventListener('change', updateEstimate);
    estimateQuantity.addEventListener('input', updateEstimate);
    updateEstimate();
}
document.querySelectorAll('.shop-calculator').forEach((calculator) => {
    const offerSelect = calculator.querySelector('.shop-estimate-offer');
    const quantity = calculator.querySelector('.shop-estimate-quantity');
    const unit = calculator.querySelector('.shop-estimate-unit');
    const output = calculator.querySelector('.shop-estimate-value');
    if (!offerSelect || !quantity || !unit || !output) return;

    const updateEstimate = () => {
        const selected = offerSelect.options[offerSelect.selectedIndex];
        const price = Number(selected?.dataset.price);
        const amount = Number(quantity.value);
        const hasOffer = Boolean(selected && selected.value);
        const value = hasOffer && Number.isFinite(amount) && amount > 0 ? price * amount : 0;
        quantity.disabled = !hasOffer;
        quantity.placeholder = hasOffer ? 'Enter quantity' : 'Select an offer first';
        unit.textContent = hasOffer ? `Quantity in ${selected.dataset.unit}` : '';
        output.textContent = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(value);
    };
    offerSelect.addEventListener('change', updateEstimate);
    quantity.addEventListener('input', updateEstimate);
    updateEstimate();
});
</script>
</body>
</html>
