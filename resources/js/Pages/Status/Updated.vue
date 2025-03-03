<script setup>
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '@/Layouts/Layout.vue';
import CustomTable from '@/Components/CustomTable.vue';
import Pagination from '@/Components/Pagination.vue';
import FloatingSuccess from '@/Components/FloatingSuccess.vue';

// Access articles from Inertia response
const { articles, userRoles } = defineProps(['articles', 'userRoles']);

function onChangePage(page) {
  const status = route().params.status || 'Revision';

  router.get(
    route('status.index', { status, page }),
    {},
    { preserveScroll: true }
  );
}

const isEditor = computed(() => userRoles.includes('editor'));
const page = usePage();
const successMessage = page.props.successMessage || '';

// Always include the 'Editor Name' column, even if hidden for editors
const headers = computed(() => [
  'Title',
  'Type of Report',
  isEditor.value ? ' ' : 'Editor Name', // Placeholder when editor_name is hidden
  'Date Submitted',
  'Approval Status',
  'Actions',
]);

// Dynamically build rows, ensuring the correct column count
const rows = computed(() => {
  return articles.data.map((article) => {
    return [
      article.title,
      article.type_of_report,
      isEditor.value ? ' ' : article.editor_name, // Empty cell for editors
      article.publication_date,
      article.approval_status,
      {
        type: 'component',
        component: Link,
        props: {
          href: route('status.view', {
            status: article.approval_status,
            id: article.id,
          }),
          class: 'text-blue-500 underline',
        },
        slot: 'View',
      },
    ];
  });
});

const exportCsv = () => {
  const status = route().params.status || 'Review';
  const exportUrl = route('articles.export', { status });

  const link = document.createElement('a');
  link.href = exportUrl;
  link.setAttribute('download', '');
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};
</script>

<template>
  <Head title="Updated" />

  <Layout>
    <FloatingSuccess v-if="successMessage" :message="successMessage" />
    <template #header>
      <h2
        class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
      >
        Updated
      </h2>
    </template>

    <div class="py-12">
      <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div
          class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
        >
          <div class="flex items-center justify-end gap-4 px-6 pt-6 w-full">
            <button
              @click="exportCsv"
              class="text-center inline-flex items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition ease-in-out hover:bg-gray-700 duration-300 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white dark:focus:bg-white dark:focus:ring-offset-gray-800 dark:active:bg-gray-300"
            >
              📥 Export CSV
            </button>
          </div>
          <div class="p-6 text-gray-900 dark:text-gray-100">
            <CustomTable
              :headers="headers"
              :rows="rows"
              :page="articles.current_page"
              :page-size="articles.per_page"
            >
              <template #empty v-if="rows.length === 0">
                <tr>
                  <td
                    colspan="5"
                    class="p-4 text-center text-gray-500 dark:text-gray-300"
                  >
                    No data available
                  </td>
                </tr>
              </template>
            </CustomTable>

            <!-- Custom Pagination -->
            <Pagination
              :current-page="articles.current_page"
              :total-pages="articles.last_page"
              :total-items="articles.total"
              :page-size="articles.per_page"
              @change-page="onChangePage"
            />
          </div>
        </div>
      </div>
    </div>
  </Layout>
</template>
