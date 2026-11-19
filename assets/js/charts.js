// =====================================================================
// TC LIMITED - Chart.js Analytics Integrations
// =====================================================================

const TC_Charts = {
    categoryChart: null,
    salesChart: null,

    renderCategoryDonut(canvasId, categoryData) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;

        if (this.categoryChart) {
            this.categoryChart.destroy();
        }

        const labels = categoryData.map(c => c.category_name);
        const data = categoryData.map(c => parseFloat(c.category_value));

        this.categoryChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#10b981', '#0ea5e9', '#f59e0b', '#ef4444', 
                        '#8b5cf6', '#ec4899', '#6366f1', '#14b8a6'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                },
                cutout: '68%'
            }
        });
    }
};

window.TC_Charts = TC_Charts;
