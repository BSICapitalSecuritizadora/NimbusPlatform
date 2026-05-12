/**
 * Gestao Documental Externa - Submission Create Script
 * Responsavel pela logica de mascaras, validacao e manipulacao de socios no formulario de criacao.
 */

(function () {
    const config = window.SubmissionConfig || {};
    let shareholders = config.shareholders || [];
    const csrfToken = config.csrfToken || '';
    const cnpjLookupUrl = config.cnpjLookupUrl || '/gestao-documental-externa/submissions/cnpj-lookup';
    const submissionDocumentTotalMaxBytes = Number(config.submissionDocumentTotalMaxBytes || (100 * 1024 * 1024));
    const submissionDocumentTotalErrorMessage = config.submissionDocumentTotalErrorMessage || 'O tamanho total de todos os arquivos nao pode ultrapassar 100 MB.';
    const documentSizeFormatter = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });

    const maskCnpj = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 14) value = value.slice(0, 14);
        value = value.replace(/^(\d{2})(\d)/, '$1.$2');
        value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
        value = value.replace(/(\d{4})(\d)/, '$1-$2');
        input.value = value;
    };

    const maskRg = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 9) value = value.slice(0, 9);
        value = value.replace(/(\d{2})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        input.value = value;
    };

    const maskCpf = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        input.value = value;
    };

    const parseJsonResponse = async (response) => {
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            return null;
        }

        return response.json();
    };

    const formatMegabytes = function (bytes) {
        return documentSizeFormatter.format(bytes / (1024 * 1024)) + ' MB';
    };

    const getSubmissionDocumentInputs = function () {
        return Array.from(document.querySelectorAll('[data-submission-document="true"]'));
    };

    const getSubmissionDocumentsTotalBytes = function () {
        return getSubmissionDocumentInputs().reduce((total, input) => {
            if (!(input instanceof HTMLInputElement) || !input.files || input.files.length === 0) {
                return total;
            }

            return total + Array.from(input.files).reduce((fileTotal, file) => fileTotal + file.size, 0);
        }, 0);
    };

    const updateSubmissionDocumentState = function () {
        const totalBytes = getSubmissionDocumentsTotalBytes();
        const totalSizeValue = document.getElementById('documentsTotalSizeValue');
        const totalSizeError = document.getElementById('documentsTotalSizeError');
        const form = document.getElementById('submissionForm');
        const submitButton = form ? form.querySelector('button[type="submit"]') : null;
        const hasExceededLimit = totalBytes > submissionDocumentTotalMaxBytes;
        const hasServerError = totalSizeError && totalSizeError.dataset.hasServerError === 'true';

        if (totalSizeValue) {
            totalSizeValue.textContent = formatMegabytes(totalBytes);
            totalSizeValue.classList.toggle('text-danger', hasExceededLimit);
        }

        if (totalSizeError) {
            if (totalBytes > 0) {
                totalSizeError.dataset.hasServerError = 'false';
            }

            totalSizeError.style.display = (hasExceededLimit || (hasServerError && totalBytes === 0)) ? 'block' : 'none';
        }

        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = hasExceededLimit;
            submitButton.setAttribute('aria-disabled', hasExceededLimit ? 'true' : 'false');
        }

        return !hasExceededLimit;
    };

    const syncShareholdersData = function () {
        const dataInput = document.getElementById('shareholdersData');

        if (dataInput) {
            dataInput.value = JSON.stringify(shareholders);
        }
    };

    const updateShareholderTotals = function () {
        const total = shareholders.reduce((sum, shareholder) => {
            return sum + parseFloat(shareholder.percentage || 0);
        }, 0);
        const totalEl = document.getElementById('totalPercentage');
        const warning = document.getElementById('percentageWarning');

        if (!totalEl) {
            return;
        }

        totalEl.textContent = total.toFixed(2);

        if (Math.abs(total - 100) > 0.01) {
            if (warning) warning.style.display = 'block';
            totalEl.parentElement.classList.add('text-danger');
            totalEl.parentElement.classList.remove('text-success');
        } else {
            if (warning) warning.style.display = 'none';
            totalEl.parentElement.classList.remove('text-danger');
            totalEl.parentElement.classList.add('text-success');
        }
    };

    const updateShareholder = function (index, field, value) {
        if (shareholders[index]) {
            shareholders[index][field] = value;
            syncShareholdersData();
            updateShareholderTotals();
        }
    };

    const removeShareholder = function (index) {
        shareholders.splice(index, 1);
        renderShareholders();
    };

    const addShareholder = function () {
        shareholders.push({ name: '', rg: '', cnpj: '', percentage: 0 });
        renderShareholders();
    };

    const renderShareholders = function () {
        const container = document.getElementById('shareholdersList');
        if (!container) return;

        container.innerHTML = '';

        shareholders.forEach((shareholder, index) => {
            const div = document.createElement('div');
            div.className = 'nd-card border-0 shadow-sm rounded-4 bg-white mb-3';
            div.innerHTML = `
                <div class="card-body p-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary text-uppercase ls-1 mb-2">Nome</label>
                            <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3" value="${shareholder.name || ''}"
                                data-shareholder-field="name" data-shareholder-index="${index}" placeholder="Nome do Socio" aria-label="Nome do socio">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary text-uppercase ls-1 mb-2">RG</label>
                            <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3" value="${shareholder.rg || ''}"
                                data-mask="rg" data-shareholder-field="rg" data-shareholder-index="${index}" placeholder="00.000.000-0" aria-label="RG do socio">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary text-uppercase ls-1 mb-2">CNPJ</label>
                            <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3" value="${shareholder.cnpj || ''}"
                                data-mask="cnpj" data-shareholder-field="cnpj" data-shareholder-index="${index}" placeholder="00.000.000/0000-00" aria-label="CNPJ do socio">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary text-uppercase ls-1 mb-2">Porcentagem (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control bg-light border-0 py-3 px-3 rounded-3 rounded-end-0" value="${shareholder.percentage || ''}"
                                    data-shareholder-field="percentage" data-shareholder-index="${index}" aria-label="Porcentagem de participacao">
                                <span class="input-group-text bg-light border-0 text-muted rounded-3 rounded-start-0">%</span>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small d-block mb-2">&nbsp;</label>
                            <div class="d-grid">
                                <button type="button" class="btn btn-outline-danger border-0 bg-danger-subtle text-danger rounded-3 d-flex align-items-center justify-content-center hover-scale" data-remove-shareholder="${index}" title="Remover Socio" aria-label="Remover socio" style="height: 52px;">
                                    <i class="bi bi-trash fs-5" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
        });

        syncShareholdersData();
        updateShareholderTotals();
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.money').forEach((input) => {
            input.addEventListener('input', function (e) {
                let value = e.target.value.replace(/\D/g, '');
                value = (parseInt(value, 10) / 100).toFixed(2);
                if (isNaN(value)) value = '0.00';
                e.target.value = 'R$ ' + value.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            });
        });

        const cnpjInput = document.getElementById('company_cnpj');
        if (cnpjInput) cnpjInput.addEventListener('input', function (e) { maskCnpj(e.target); });

        const cpfInput = document.getElementById('registrant_cpf');
        if (cpfInput) cpfInput.addEventListener('input', function (e) { maskCpf(e.target); });

        const rgInput = document.getElementById('registrant_rg');
        if (rgInput) rgInput.addEventListener('input', function (e) { maskRg(e.target); });

        const btnSearch = document.getElementById('btnSearchCnpj');
        if (btnSearch) {
            btnSearch.addEventListener('click', async function () {
                const cnpj = document.getElementById('company_cnpj').value;
                const btn = this;
                const originalContent = btn.innerHTML;

                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Acessando...';

                try {
                    const formData = new FormData();
                    formData.append('cnpj', cnpj);
                    formData.append('_token', csrfToken);

                    const response = await fetch(cnpjLookupUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    });

                    const result = await parseJsonResponse(response);

                    if (!response.ok) {
                        const message = result?.error || result?.message || 'Nao foi possivel consultar o CNPJ.';
                        throw new Error(message);
                    }

                    if (!result || result.error) {
                        alert(result?.error || 'Nao foi possivel consultar o CNPJ.');
                    } else {
                        const nameField = document.getElementById('company_name');
                        if (nameField) nameField.value = result.data.name || '';

                        const activityField = document.getElementById('main_activity');
                        if (activityField) activityField.value = result.data.main_activity || '';

                        const phoneField = document.getElementById('phone');
                        if (phoneField) phoneField.value = result.data.phone || '';

                        const websiteField = document.getElementById('website');
                        if (websiteField && !websiteField.value) websiteField.value = result.data.website || '';
                    }
                } catch (error) {
                    alert('Erro ao buscar CNPJ: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            });
        }

        const usPerson = document.getElementById('is_us_person');
        const pep = document.getElementById('is_pep');
        const noneCompliant = document.getElementById('is_none_compliant');
        const complianceError = document.getElementById('complianceError');
        const anbimaAffiliationRadios = document.querySelectorAll('input[name="is_anbima_affiliated"]');
        const anbimaAffiliationError = document.getElementById('anbimaAffiliationError');

        if (usPerson && pep && noneCompliant) {
            function updateComplianceChecks(e) {
                if (e.target === noneCompliant && noneCompliant.checked) {
                    usPerson.checked = false;
                    pep.checked = false;
                } else if ((e.target === usPerson || e.target === pep) && e.target.checked) {
                    noneCompliant.checked = false;
                }

                if (usPerson.checked || pep.checked || noneCompliant.checked) {
                    if (complianceError) complianceError.style.display = 'none';
                    usPerson.classList.remove('is-invalid');
                    pep.classList.remove('is-invalid');
                    noneCompliant.classList.remove('is-invalid');
                    usPerson.removeAttribute('aria-invalid');
                    pep.removeAttribute('aria-invalid');
                    noneCompliant.removeAttribute('aria-invalid');
                }
            }

            usPerson.addEventListener('change', updateComplianceChecks);
            pep.addEventListener('change', updateComplianceChecks);
            noneCompliant.addEventListener('change', updateComplianceChecks);
        }

        if (anbimaAffiliationRadios.length > 0) {
            anbimaAffiliationRadios.forEach((radio) => {
                radio.addEventListener('change', function () {
                    anbimaAffiliationRadios.forEach((input) => {
                        input.classList.remove('is-invalid');
                        input.removeAttribute('aria-invalid');
                    });

                    if (anbimaAffiliationError) {
                        anbimaAffiliationError.style.display = 'none';
                    }
                });
            });
        }

        const btnAddShareholder = document.getElementById('btnAddShareholder');
        if (btnAddShareholder) {
            btnAddShareholder.addEventListener('click', addShareholder);
        }

        const shareholdersList = document.getElementById('shareholdersList');
        if (shareholdersList) {
            shareholdersList.addEventListener('input', function (event) {
                const target = event.target;

                if (!(target instanceof HTMLInputElement)) {
                    return;
                }

                if (target.dataset.mask === 'rg') {
                    maskRg(target);
                }

                if (target.dataset.mask === 'cnpj') {
                    maskCnpj(target);
                }

                const field = target.dataset.shareholderField;
                const index = Number(target.dataset.shareholderIndex);

                if (!field || Number.isNaN(index)) {
                    return;
                }

                updateShareholder(index, field, target.value);
            });

            shareholdersList.addEventListener('click', function (event) {
                if (!(event.target instanceof Element)) {
                    return;
                }

                const removeTrigger = event.target.closest('[data-remove-shareholder]');

                if (!removeTrigger) {
                    return;
                }

                const index = Number(removeTrigger.getAttribute('data-remove-shareholder'));

                if (!Number.isNaN(index)) {
                    removeShareholder(index);
                }
            });
        }

        renderShareholders();

        const form = document.getElementById('submissionForm');
        if (form) {
            getSubmissionDocumentInputs().forEach((input) => {
                input.addEventListener('change', updateSubmissionDocumentState);
            });

            updateSubmissionDocumentState();

            form.addEventListener('submit', function (e) {
                if (!validateStep(3)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        }

        let currentStep = 1;

        function showStep(step) {
            document.querySelectorAll('.wizard-step').forEach((el) => {
                el.classList.remove('active');
            });

            const targetStepEl = document.querySelector(`.wizard-step[data-step="${step}"]`);
            if (targetStepEl) {
                targetStepEl.classList.add('active');
            }

            const steps = document.querySelectorAll('.nd-step-item');
            const progress = document.getElementById('stepperProgress');

            steps.forEach((el) => {
                const target = parseInt(el.getAttribute('data-target'), 10);
                const box = el.querySelector('.nd-step-box');

                el.classList.remove('active');

                box.className = 'nd-step-box border border-2 d-flex align-items-center justify-content-center mb-2 mx-auto transition-fast';
                box.style.width = '48px';
                box.style.height = '48px';
                box.style.fontSize = '1.25rem';

                if (target === step) {
                    el.classList.add('active');
                    box.classList.add('bg-white', 'text-warning', 'border-warning', 'shadow-sm');
                    box.classList.remove('text-muted', 'border-light-subtle', 'bg-success', 'border-success', 'text-white');
                    box.innerHTML = target;
                } else if (target < step) {
                    box.classList.add('bg-white', 'text-success', 'border-success', 'shadow-sm');
                    box.classList.remove('text-muted', 'border-light-subtle', 'bg-warning', 'border-warning', 'text-warning');
                    box.innerHTML = '<i class="bi bi-check-lg"></i>';
                } else {
                    box.classList.add('bg-white', 'text-muted', 'border-light-subtle');
                    box.classList.remove('bg-warning', 'text-warning', 'border-warning', 'bg-success', 'border-success', 'shadow-sm');
                    box.innerHTML = target;
                }
            });

            if (progress && steps.length > 1) {
                const percentage = ((step - 1) / (steps.length - 1)) * 100;
                progress.style.width = percentage + '%';
            }

            currentStep = step;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function validateStep(step) {
            let isValid = true;
            const stepEl = document.querySelector(`.wizard-step[data-step="${step}"]`);

            const inputs = stepEl.querySelectorAll('input, select, textarea');
            inputs.forEach((input) => {
                if ((input instanceof HTMLInputElement) && input.type === 'radio' && input.name === 'is_anbima_affiliated') {
                    return;
                }

                if (!input.checkValidity()) {
                    isValid = false;
                    input.classList.add('is-invalid');
                    input.reportValidity();
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (step === 1) {
                if (usPerson && pep && noneCompliant && !usPerson.checked && !pep.checked && !noneCompliant.checked) {
                    isValid = false;
                    if (complianceError) complianceError.style.display = 'block';
                    usPerson.classList.add('is-invalid');
                    pep.classList.add('is-invalid');
                    noneCompliant.classList.add('is-invalid');
                    if (complianceError) complianceError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                const hasAnbimaAffiliation = Array.from(anbimaAffiliationRadios).some((radio) => radio.checked);

                if (anbimaAffiliationRadios.length > 0 && !hasAnbimaAffiliation) {
                    isValid = false;

                    anbimaAffiliationRadios.forEach((radio) => {
                        radio.classList.add('is-invalid');
                        radio.setAttribute('aria-invalid', 'true');
                    });

                    if (anbimaAffiliationError) {
                        anbimaAffiliationError.style.display = 'block';
                        anbimaAffiliationError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            }

            if (step === 2) {
                const totalEl = document.getElementById('totalPercentage');
                if (totalEl) {
                    const total = parseFloat(totalEl.textContent);

                    if (shareholders.length > 0 && Math.abs(total - 100) > 0.01) {
                        isValid = false;
                        const warning = document.getElementById('percentageWarning');
                        if (warning) {
                            warning.style.display = 'block';
                            warning.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        alert('A soma das participacoes deve ser 100%');
                    }
                }
            }

            if (step === 3 && !updateSubmissionDocumentState()) {
                isValid = false;

                const totalSizeError = document.getElementById('documentsTotalSizeError');

                if (totalSizeError) {
                    totalSizeError.textContent = submissionDocumentTotalErrorMessage;
                    totalSizeError.style.display = 'block';
                    totalSizeError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            return isValid;
        }

        document.querySelectorAll('.btn-next').forEach((btn) => {
            btn.addEventListener('click', function () {
                const nextStep = parseInt(this.getAttribute('data-next'), 10);
                if (validateStep(currentStep)) {
                    showStep(nextStep);
                }
            });
        });

        document.querySelectorAll('.btn-prev').forEach((btn) => {
            btn.addEventListener('click', function () {
                const prevStep = parseInt(this.getAttribute('data-prev'), 10);
                showStep(prevStep);
            });
        });
    });
})();
