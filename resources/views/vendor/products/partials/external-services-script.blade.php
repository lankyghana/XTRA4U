@push('scripts')
<script>
(function () {
    const configEl = document.getElementById('external-services-config');
    const endpoint = configEl ? (configEl.dataset.endpoint || '') : '';
    const providerName = configEl ? (configEl.dataset.provider || '') : '';
    const initialServiceId = configEl ? (configEl.dataset.serviceId || '') : '';
    const initialServiceName = configEl ? (configEl.dataset.serviceName || '') : '';
    const initialServiceNetwork = configEl ? (configEl.dataset.serviceNetwork || '') : '';
    const initialServiceCapacity = configEl ? (configEl.dataset.serviceCapacity || '') : '';
    const initialServicePrice = configEl ? (configEl.dataset.servicePrice || '') : '';
    const initialServiceOfferSlug = configEl ? (configEl.dataset.serviceOfferSlug || '') : '';

    const serviceSelect = document.getElementById('external_service_id');
    const refreshButton = document.getElementById('external_services_refresh');
    const statusEl = document.getElementById('external_services_status');
    const detailsEl = document.getElementById('external_service_details');

    if (!serviceSelect || !statusEl || endpoint === '') {
        return;
    }

    const nameInput = document.getElementById('external_service_name');
    const networkInput = document.getElementById('external_service_network');
    const capacityInput = document.getElementById('external_service_capacity');
    const priceInput = document.getElementById('external_service_price');
    const offerSlugInput = document.getElementById('external_service_offer_slug');
    const externalNetworkSelect = document.getElementById('external_network');

    const fallbackLabel = providerName ? providerName + ' service' : 'External service';

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.classList.remove('text-gray-400', 'text-red-600', 'text-green-600');
        statusEl.classList.add(isError ? 'text-red-600' : 'text-gray-400');
    }

    function sanitizeString(value) {
        if (value === null || typeof value === 'undefined') {
            return '';
        }

        return String(value).trim();
    }

    function parseNumeric(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '';
        }

        const parsed = Number(value);
        return Number.isFinite(parsed) ? String(parsed) : '';
    }

    function normalizeService(raw, index) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        const id = sanitizeString(raw.id || raw.service_id || raw.code || raw.key || raw.reference || ('service_' + index));
        const name = sanitizeString(raw.name || raw.title || raw.label || raw.service_name || id || (fallbackLabel + ' ' + (index + 1)));
        const network = sanitizeString(raw.network || raw.carrier || raw.provider);
        const capacity = sanitizeString(raw.capacity || raw.size || raw.volume);
        const price = parseNumeric(raw.price || raw.amount || raw.cost || raw.rate);
        const offerSlug = sanitizeString(raw.offer_slug || raw.offerSlug || raw.offer || '');

        return {
            id,
            name,
            network,
            capacity,
            price,
            offerSlug,
            raw,
        };
    }

    function renderServiceDetails(service) {
        if (!detailsEl) {
            return;
        }

        if (!service || !service.id) {
            detailsEl.textContent = '';
            return;
        }

        const parts = [];
        if (service.network) {
            parts.push('Network: ' + service.network);
        }
        if (service.capacity) {
            parts.push('Capacity: ' + service.capacity);
        }
        if (service.price) {
            parts.push('Price: GHS ' + service.price);
        }

        detailsEl.textContent = parts.join(' | ');
    }

    function applySelection(service) {
        if (!service || !service.id) {
            if (nameInput) nameInput.value = '';
            if (networkInput) networkInput.value = '';
            if (capacityInput) capacityInput.value = '';
            if (priceInput) priceInput.value = '';
            if (offerSlugInput) offerSlugInput.value = '';
            renderServiceDetails(null);
            return;
        }

        if (nameInput) nameInput.value = service.name || '';
        if (networkInput) networkInput.value = service.network || '';
        if (capacityInput) capacityInput.value = service.capacity || '';
        if (priceInput) priceInput.value = service.price || '';
        if (offerSlugInput) offerSlugInput.value = service.offerSlug || '';

        if (externalNetworkSelect && service.network) {
            const preferred = service.network.toUpperCase();
            const options = Array.from(externalNetworkSelect.options || []);
            const exact = options.find((option) => String(option.value).toUpperCase() === preferred);

            if (exact && !externalNetworkSelect.value) {
                externalNetworkSelect.value = exact.value;
            }
        }

        renderServiceDetails(service);
    }

    function buildOption(service) {
        const option = document.createElement('option');
        option.value = service.id;

        const suffix = [];
        if (service.network) suffix.push(service.network);
        if (service.capacity) suffix.push(service.capacity);
        if (service.price) suffix.push('GHS ' + service.price);

        option.textContent = suffix.length ? service.name + ' (' + suffix.join(' | ') + ')' : service.name;
        option.dataset.name = service.name || '';
        option.dataset.network = service.network || '';
        option.dataset.capacity = service.capacity || '';
        option.dataset.price = service.price || '';
        option.dataset.offerSlug = service.offerSlug || '';

        return option;
    }

    function optionToService(option) {
        if (!option || !option.value) {
            return null;
        }

        return {
            id: option.value,
            name: option.dataset.name || option.textContent || option.value,
            network: option.dataset.network || '',
            capacity: option.dataset.capacity || '',
            price: option.dataset.price || '',
            offerSlug: option.dataset.offerSlug || '',
        };
    }

    function setLoadingState(isLoading) {
        serviceSelect.disabled = isLoading;
        if (refreshButton) {
            refreshButton.disabled = isLoading;
        }
    }

    async function fetchServices(preferredServiceId) {
        setLoadingState(true);
        setStatus('Loading services...', false);

        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();
            const rawServices = Array.isArray(data.services) ? data.services : [];
            const responseMessage = typeof data.message === 'string' ? data.message.trim() : '';
            const services = rawServices
                .map((item, index) => normalizeService(item, index))
                .filter((item) => item && item.id);

            const oldValue = preferredServiceId || serviceSelect.value || initialServiceId;

            serviceSelect.innerHTML = '';

            const autoOption = document.createElement('option');
            autoOption.value = '';
            autoOption.textContent = 'Auto (recommended)';
            serviceSelect.appendChild(autoOption);

            services.forEach((service) => {
                serviceSelect.appendChild(buildOption(service));
            });

            if (oldValue) {
                const hasOldValue = services.some((service) => service.id === oldValue);

                if (!hasOldValue) {
                    const preservedOption = document.createElement('option');
                    preservedOption.value = oldValue;
                    preservedOption.textContent = (initialServiceName || oldValue) + ' (saved)';
                    preservedOption.dataset.name = initialServiceName || oldValue;
                    preservedOption.dataset.network = initialServiceNetwork || '';
                    preservedOption.dataset.capacity = initialServiceCapacity || '';
                    preservedOption.dataset.price = initialServicePrice || '';
                    preservedOption.dataset.offerSlug = initialServiceOfferSlug || '';
                    serviceSelect.appendChild(preservedOption);
                }

                serviceSelect.value = oldValue;
                applySelection(optionToService(serviceSelect.selectedOptions[0]));
            } else {
                applySelection(null);
            }

            if (responseMessage && services.length === 0) {
                setStatus(responseMessage, true);
            } else {
                setStatus('Loaded ' + services.length + ' services.', false);
                statusEl.classList.remove('text-gray-400', 'text-red-600');
                statusEl.classList.add('text-green-600');
            }
        } catch (error) {
            setStatus('Unable to load services right now.', true);
            applySelection(optionToService(serviceSelect.selectedOptions[0]));
        } finally {
            setLoadingState(false);
        }
    }

    serviceSelect.addEventListener('change', function () {
        applySelection(optionToService(serviceSelect.selectedOptions[0]));
    });

    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            fetchServices(serviceSelect.value || initialServiceId);
        });
    }

    if (initialServiceId) {
        if (nameInput) nameInput.value = initialServiceName;
        if (networkInput) networkInput.value = initialServiceNetwork;
        if (capacityInput) capacityInput.value = initialServiceCapacity;
        if (priceInput) priceInput.value = initialServicePrice;
        if (offerSlugInput) offerSlugInput.value = initialServiceOfferSlug;
    }

    fetchServices(initialServiceId);
})();
</script>
@endpush
