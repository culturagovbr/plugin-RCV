app.component('rcv-relatorio-table', {
    template: $TEMPLATES['rcv-relatorio-table'],

    setup() {
        const text = Utils.getTexts('rcv-relatorio-table')
        return { text }
    },

    props: {
        cenarios: {
            type: String,
            default: ''
        }
    },

    data() {
        return {
            loading: false,
            error: null,
            dados: [],
            cenariosSelected: [],
            statusFilter: 'all', 
            colunasFixas: ['Estado', 'Município', 'Nome da organização', 'Cenário', 'Inscrição PNAB'],
            colunasVisiveis: [],
            mostrarSeletorColunas: false,
            currentPage: 1,
            itemsPerPage: 20,
            totalRegistrations: 0,
            totalPages: 0,
            resultsInPage: 0
        }
    },

    computed: {
        todasColunas() {
            if (this.dados.length === 0) return [];
            return Object.keys(this.dados[0]);
        },

        colunaVisivel() {
            return (coluna) => this.colunasVisiveis.includes(coluna);
        },

        hasPreviousPage() {
            return this.currentPage > 1;
        },

        hasNextPage() {
            return this.currentPage < this.totalPages;
        },

        paginationInfo() {
            if (this.totalRegistrations === 0) return 'Nenhuma inscrição';
            return `Página ${this.currentPage} de ${this.totalPages} | ${this.resultsInPage} resultados (${this.totalRegistrations}  na oportunidade PNAB)`;
        }
    },

    mounted() {
        if (this.cenarios) {
            this.cenariosSelected = this.cenarios.split(',').map(c => c.trim());
        }
        this.loadData();
    },

    methods: {
        async loadData() {
            this.loading = true;
            this.error = null;

            try {
                let url = Utils.createUrl('rcv', 'getdata');
                let params = [];
                
                params.push('page=' + this.currentPage);
                params.push('limit=' + this.itemsPerPage);
                
                if (this.cenariosSelected.length > 0) {
                    params.push(...this.cenariosSelected.map(c => 'cenario[]=' + encodeURIComponent(c)));
                }

                if (this.statusFilter === 'errors') {
                    params.push('apenas_erros=1');
                } else if (this.statusFilter === 'all') {
                    params.push('mostrar_tudo=1');
                }

                if (params.length > 0) {
                    url += '?' + params.join('&');
                }

                const api = new API();
                const response = await api.GET(url);
                const responseData = await response.json();
                
                if (responseData.metadata) {
                    this.dados = responseData.data || [];
                    this.totalRegistrations = responseData.metadata.total_registrations;
                    this.totalPages = responseData.metadata.total_pages;
                    this.resultsInPage = responseData.metadata.results_in_page;
                } else {
                    this.dados = responseData || [];
                }
                
                if (this.colunasVisiveis.length === 0 && this.dados.length > 0) {
                    this.inicializarColunasVisiveis();
                }
                
            } catch (err) {
                console.error('Erro ao carregar dados:', err);
                this.error = 'Erro ao carregar os dados: ' + err.message;
            } finally {
                this.loading = false;
            }
        },

        inicializarColunasVisiveis() {
            this.colunasVisiveis = [...this.colunasFixas];
        },

        toggleColuna(coluna) {
            const index = this.colunasVisiveis.indexOf(coluna);
            if (index > -1) {
                this.colunasVisiveis.splice(index, 1);
            } else {
                this.colunasVisiveis.push(coluna);
            }
        },

        isColunaFixa(coluna) {
            return this.colunasFixas.includes(coluna);
        },

        toggleSeletorColunas() {
            this.mostrarSeletorColunas = !this.mostrarSeletorColunas;
        },

        applyFilters() {
            this.currentPage = 1;
            this.loadData();
        },

        exportarExcel() {
            let url = Utils.createUrl('rcv', 'exportExcel');
            let params = [];
            
            if (this.cenariosSelected.length > 0) {
                params.push(...this.cenariosSelected.map(c => 'cenario[]=' + encodeURIComponent(c)));
            }

            if (this.statusFilter === 'errors') {
                params.push('apenas_erros=1');
            } else if (this.statusFilter === 'all') {
                params.push('mostrar_tudo=1');
            }

            if (params.length > 0) {
                url += '?' + params.join('&');
            }

            window.open(url, '_blank');
        },

        nextPage() {
            if (this.hasNextPage) {
                this.currentPage++;
                this.loadData();
            }
        },

        prevPage() {
            if (this.hasPreviousPage) {
                this.currentPage--;
                this.loadData();
            }
        },

        goToPage(page) {
            const pageNum = parseInt(page);
            if (pageNum >= 1 && pageNum <= this.totalPages) {
                this.currentPage = pageNum;
                this.loadData();
            }
        },

        changeItemsPerPage(newLimit) {
            this.itemsPerPage = parseInt(newLimit);
            this.currentPage = 1; 
            this.loadData();
        }
    }
});
