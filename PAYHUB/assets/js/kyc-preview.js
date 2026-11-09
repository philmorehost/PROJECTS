// php-version/assets/js/kyc-preview.js

function initKYCPreview() {
    const fileInputs = document.querySelectorAll('input[type="file"]');

    fileInputs.forEach(input => {
        // Create preview container if not exists
        let previewContainer = input.parentElement.querySelector('.kyc-preview-container');
        if (!previewContainer) {
            previewContainer = document.createElement('div');
            previewContainer.className = 'kyc-preview-container hidden mt-4 transition-all duration-300';
            input.parentElement.appendChild(previewContainer);
        }

        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(event) {
                const isPDF = file.type === 'application/pdf';

                // Hide default icon and text
                const icon = input.parentElement.querySelector('i');
                const textElements = input.parentElement.querySelectorAll('p');
                if (icon) icon.classList.add('hidden');
                textElements.forEach(p => p.classList.add('hidden'));

                previewContainer.innerHTML = '';
                previewContainer.classList.remove('hidden');

                if (isPDF) {
                    previewContainer.innerHTML = `
                        <div class="flex flex-col items-center gap-2 p-4 bg-slate-50 rounded-2xl border border-slate-200">
                            <div class="w-12 h-12 bg-red-100 text-red-600 rounded-xl flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                            </div>
                            <span class="text-[10px] font-bold text-slate-500 uppercase truncate max-w-[150px]">${file.name}</span>
                            <button type="button" onclick="resetInput(this)" class="text-[9px] font-bold text-rose-500 hover:text-rose-700">Remove</button>
                        </div>
                    `;
                } else {
                    previewContainer.innerHTML = `
                        <div class="relative group">
                            <img src="${event.target.result}" class="w-full h-32 object-cover rounded-2xl border border-slate-200 shadow-sm">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl flex items-center justify-center gap-2">
                                <button type="button" onclick="resetInput(this)" class="p-2 bg-white/20 hover:bg-white/40 text-white rounded-lg backdrop-blur-md transition-all">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                </button>
                            </div>
                        </div>
                    `;
                }
            };
            reader.readAsDataURL(file);
        });
    });
}

function resetInput(btn) {
    const container = btn.closest('.kyc-preview-container');
    const parent = container.parentElement;
    const input = parent.querySelector('input[type="file"]');
    const icon = parent.querySelector('i');
    const textElements = parent.querySelectorAll('p');

    input.value = '';
    container.classList.add('hidden');
    container.innerHTML = '';

    if (icon) icon.classList.remove('hidden');
    textElements.forEach(p => p.classList.remove('hidden'));
}

document.addEventListener('DOMContentLoaded', initKYCPreview);
