/**
 * NimbusDocs - Submission Create Script
 * Responsável pela lógica de máscaras, validação e manipulação de sócios no formulário de criação.
 */

(function () {
    const config = window.SubmissionConfig || {};
    let shareholders = config.shareholders || [];
    const csrfToken = config.csrfToken || '';

    // Funções auxiliares globais (necessárias para o HTML gerado dinamicamente)
    window.maskCnpj = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 14) value = value.slice(0, 14);
        value = value.replace(/^(\d{2})(\d)/, '$1.$2');
        value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
        value = value.replace(/(\d{4})(\d)/, '$1-$2');
        input.value = value;
    };

    window.maskRg = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 9) value = value.slice(0, 9);
        value = value.replace(/(\d{2})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        input.value = value;
    };

    window.maskCpf = function (input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        input.value = value;
    };

    window.updateShareholder = function (index, field, value) {
        if (shareholders[index]) {
            shareholders[index][field] = value;
            document.getElementById('shareholdersData').value = JSON.stringify(shareholders);
        }
    };

    window.removeShareholder = function (index) {
        shareholders.splice(index, 1);
        renderShareholders();
    };

    window.addShareholder = function () {
        shareholders.push({ name: '', rg: '', cnpj: '', percentage: 0 });
        renderShareholders();
    };

    // Função principal de renderização
    window.renderShareholders = function () {
        const container = document.getElementById('shareholdersList');
        if (!container) return;

        container.innerHTML = '';
        let total = 0;

        shareholders.forEach((shareholder, index) => {
            total += parseFloat(shareholder.percentage || 0);

            const div = document.createElement('div');
            div.className = 'nd-card border-light-subtle shadow-none bg-light';
            div.innerHTML = `
                <div class="card-body p-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="nd-label small mb-1">Nome</label>
                            <input type="text" class="nd-input bg-white" value="${shareholder.name || ''}"
                                onchange="updateShareholder(${index}, 'name', this.value)" placeholder="Nome do Sócio" aria-label="Nome do sócio">
                        </div>
                        <div class="col-md-2">
                            <label class="nd-label small mb-1">RG</label>
                            <input type="text" class="nd-input bg-white" value="${shareholder.rg || ''}"
                                oninput="maskRg(this)"
                                onchange="updateShareholder(${index}, 'rg', this.value)" placeholder="00.000.000-0" aria-label="RG do sócio">
                        </div>
                        <div class="col-md-3">
                            <label class="nd-label small mb-1">CNPJ</label>
                            <input type="text" class="nd-input bg-white" value="${shareholder.cnpj || ''}"
                                oninput="maskCnpj(this)"
                                onchange="updateShareholder(${index}, 'cnpj', this.value)" placeholder="00.000.000/0000-00" aria-label="CNPJ do sócio">
                        </div>
                        <div class="col-md-2">
                            <label class="nd-label small mb-1">Porcentagem (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="nd-input form-control bg-white" value="${shareholder.percentage || ''}"
                                    onchange="updateShareholder(${index}, 'percentage', this.value); renderShareholders();" aria-label="Porcentagem de participação">
                                <span class="input-group-text bg-white border-start-0 text-muted">%</span>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <label class="nd-label small mb-1 d-block">&nbsp;</label>
                            <div class="d-grid">
                                <button type="button" class="nd-btn nd-btn-sm nd-btn-danger-soft" onclick="removeShareholder(${index})" title="Remover Sócio" aria-label="Remover sócio" style="height: 48px;">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
        });

        const totalEl = document.getElementById('totalPercentage');
        const warning = document.getElementById('percentageWarning');

        if (totalEl) {
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
        }

        const dataInput = document.getElementById('shareholdersData');
        if (dataInput) {
            dataInput.value = JSON.stringify(shareholders);
        }
    };

    // Inicialização ao carregar o DOM
    document.addEventListener('DOMContentLoaded', function () {
        // Máscara para valores monetários
        document.querySelectorAll('.money').forEach(input => {
            input.addEventListener('input', function (e) {
                let value = e.target.value.replace(/\D/g, '');
                value = (parseInt(value) / 100).toFixed(2);
                if (isNaN(value)) value = "0.00";
                e.target.value = 'R$ ' + value.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            });
        });

        // Attach masks to static inputs
        const cnpjInput = document.getElementById('company_cnpj');
        if (cnpjInput) cnpjInput.addEventListener('input', function (e) { maskCnpj(e.target); });

        const cpfInput = document.getElementById('registrant_cpf');
        if (cpfInput) cpfInput.addEventListener('input', function (e) { maskCpf(e.target); });

        const rgInput = document.getElementById('registrant_rg');
        if (rgInput) rgInput.addEventListener('input', function (e) { maskRg(e.target); });

        // Buscar CNPJ
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

                    const response = await fetch('/portal/api/cnpj', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.error) {
                        alert(result.error);
                    } else {
                        const nameField = document.getElementById('company_name');
                        if (nameField) nameField.value = result.data.name || '';

                        const activityField = document.getElementById('main_activity');
                        if (activityField) activityField.value = result.data.main_activity || '';

                        const phoneField = document.getElementById('phone');
                        if (phoneField) phoneField.value = result.data.phone || '';
                    }
                } catch (error) {
                    alert('Erro ao buscar CNPJ: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            });
        }

        // Compliance
        const usPerson = document.getElementById('is_us_person');
        const pep = document.getElementById('is_pep');
        const noneCompliant = document.getElementById('is_none_compliant');
        const complianceError = document.getElementById('complianceError');

        if (usPerson && pep && noneCompliant) {
            function updateComplianceChecks(e) {
                if (e.target === noneCompliant && noneCompliant.checked) {
                    usPerson.checked = false;
                    pep.checked = false;
                } else if ((e.target === usPerson || e.target === pep) && e.target.checked) {
                    noneCompliant.checked = false;
                }

                // Hide error if any is checked
                if (usPerson.checked || pep.checked || noneCompliant.checked) {
                    if (complianceError) complianceError.style.display = 'none';
                    usPerson.classList.remove('is-invalid');
                    pep.classList.remove('is-invalid');
                    noneCompliant.classList.remove('is-invalid');

                    // A11y update
                    usPerson.removeAttribute('aria-invalid');
                    pep.removeAttribute('aria-invalid');
                    noneCompliant.removeAttribute('aria-invalid');
                }
            }

            usPerson.addEventListener('change', updateComplianceChecks);
            pep.addEventListener('change', updateComplianceChecks);
            noneCompliant.addEventListener('change', updateComplianceChecks);
        }

        // Add Shareholder Button
        const btnAddShareholder = document.getElementById('btnAddShareholder');
        if (btnAddShareholder) {
            btnAddShareholder.addEventListener('click', addShareholder);
        }

        // Initial Render
        renderShareholders();

        // Validation on Submit
        const form = document.getElementById('submissionForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!validateStep(3)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        }

        // ==========================================
        // Wizard Logic
        // ==========================================
        let currentStep = 1;
        const totalSteps = 3;

        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.wizard-step').forEach(el => {
                el.classList.remove('active');
            });

            // Show target step
            const targetStepEl = document.querySelector(`.wizard-step[data-step="${step}"]`);
            if (targetStepEl) {
                targetStepEl.classList.add('active');
            }

            // Update Stepper UI (Premium Design)
            const steps = document.querySelectorAll('.nd-step-item');
            const progress = document.getElementById('stepperProgress');

            steps.forEach(el => {
                const s = parseInt(el.getAttribute('data-target'));
                const box = el.querySelector('.nd-step-box');

                el.classList.remove('active');

                // Reset standard classes
                box.className = 'nd-step-box border border-2 d-flex align-items-center justify-content-center mb-2 mx-auto transition-fast';
                box.style.width = '48px';
                box.style.height = '48px';
                box.style.fontSize = '1.25rem';

                if (s === step) {
                    // Active Step (Warning Border, White BG)
                    el.classList.add('active');
                    box.classList.add('bg-white', 'text-warning', 'border-warning', 'shadow-sm');
                    box.classList.remove('text-muted', 'border-light-subtle', 'bg-success', 'border-success', 'text-white');
                    box.innerHTML = s;
                } else if (s < step) {
                    // Completed Step (Success Border, White BG)
                    box.classList.add('bg-white', 'text-success', 'border-success', 'shadow-sm');
                    box.classList.remove('text-muted', 'border-light-subtle', 'bg-warning', 'border-warning', 'text-warning');
                    box.innerHTML = '<i class="bi bi-check-lg"></i>';
                } else {
                    // Future Step (Muted Border, White BG)
                    box.classList.add('bg-white', 'text-muted', 'border-light-subtle');
                    box.classList.remove('bg-warning', 'text-warning', 'border-warning', 'bg-success', 'border-success', 'shadow-sm');
                    box.innerHTML = s;
                }
            });

            // Update Progress Bar
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

            // 1. HTML5 Validation for inputs in this step
            const inputs = stepEl.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (!input.checkValidity()) {
                    isValid = false;
                    input.classList.add('is-invalid');
                    input.reportValidity(); // Optional: shows browser tooltip
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            // 2. Custom Validation
            if (step === 1) {
                // Compliance Check
                if (usPerson && pep && noneCompliant && !usPerson.checked && !pep.checked && !noneCompliant.checked) {
                    isValid = false;
                    if (complianceError) complianceError.style.display = 'block';
                    usPerson.classList.add('is-invalid');
                    pep.classList.add('is-invalid');
                    noneCompliant.classList.add('is-invalid');
                    if (complianceError) complianceError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            if (step === 2) {
                // Shareholder Percentage Check
                const totalEl = document.getElementById('totalPercentage');
                if (totalEl) {
                    const total = parseFloat(totalEl.textContent);
                    // Ensure at least one shareholder if strictly required (optional, but logical)
                    // And check 100%
                    if (shareholders.length === 0) {
                        // Optional: Require at least one? The Controller validates it?
                        // Let's assume yes.
                        // alert('Adicione pelo menos um sócio.');
                        // isValid = false; 
                        // (Keeping loose to avoid blocking if optional, but 100% check implies data)
                    }

                    if (shareholders.length > 0 && Math.abs(total - 100) > 0.01) {
                        isValid = false;
                        const warning = document.getElementById('percentageWarning');
                        if (warning) {
                            warning.style.display = 'block';
                            warning.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        alert('A soma das participações deve ser 100%');
                    }
                }
            }

            return isValid;
        }

        // Next Buttons
        document.querySelectorAll('.btn-next').forEach(btn => {
            btn.addEventListener('click', function () {
                const nextStep = parseInt(this.getAttribute('data-next'));
                if (validateStep(currentStep)) {
                    showStep(nextStep);
                }
            });
        });

        // Prev Buttons
        document.querySelectorAll('.btn-prev').forEach(btn => {
            btn.addEventListener('click', function () {
                const prevStep = parseInt(this.getAttribute('data-prev'));
                showStep(prevStep);
            });
        });

    });

})();
