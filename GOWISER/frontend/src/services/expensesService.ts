import apiClient from '../config/api';

interface ApiResponse<T> {
  status: string;
  data: T;
  message?: string;
}

/**
 * 'daily' and 'monthly' are bucket tags. They decide which summary card an expense
 * lands in on the Expenses page — they do not recur, accrue, or generate rows.
 */
export type ExpenseType = 'daily' | 'monthly';

export const EXPENSE_TYPES: { value: ExpenseType; label: string; hint: string }[] = [
  { value: 'daily', label: 'Daily', hint: 'Counted in the daily expenses total' },
  { value: 'monthly', label: 'Monthly', hint: 'Counted in the monthly expenses total' },
];

export interface Expense {
  id: string;
  expensesId: string;
  date: string;
  dateRaw: string;
  amount: number;
  payee: string;
  category: string;
  categoryId: number | null;
  expenseType: ExpenseType;
  description: string;
  invoiceNo: string;
  referenceNo: string;
  provider: string;
  photo?: string | null;
  processedBy: string;
  modifiedBy: string;
  modifiedDate: string;
  userEmail: string;
  receivedDate: string;
  receivedDateRaw: string;
  supplier: string;
  location: string;
  barangay: string;
  city: string;
  organization_id?: number | null;
}

export interface ExpenseSummary {
  daily_today: number;
  daily_this_month: number;
  monthly_this_month: number;
  total_this_month: number;
  count_today: number;
  count_this_month: number;
  by_category: { label: string; value: number }[];
  scope: { today: string; month_start: string; month_end: string };
}

export interface ExpenseFilters {
  search?: string;
  expense_type?: ExpenseType | '';
  category_id?: number | '';
  date_from?: string;
  date_to?: string;
}

export interface ExpensePayload {
  date: string;
  amount: number | string;
  expense_type: ExpenseType;
  category_id?: number | null;
  payee?: string;
  description?: string;
  invoice_no?: string;
  reference_no?: string;
  provider?: string;
  supplier?: string;
  location?: string;
  barangay?: string;
  city?: string;
  received_date?: string;
  receipt?: File | null;
}

const toQueryParams = (filters?: ExpenseFilters) => {
  const params: Record<string, string> = {};
  if (!filters) return params;

  if (filters.search) params.search = filters.search;
  if (filters.expense_type) params.expense_type = filters.expense_type;
  if (filters.category_id) params.category_id = String(filters.category_id);
  if (filters.date_from) params.date_from = filters.date_from;
  if (filters.date_to) params.date_to = filters.date_to;

  return params;
};

/**
 * Receipts are files, so writes go out as multipart. Blank optional fields are
 * skipped rather than sent as "" — FormData stringifies everything, and an empty
 * string would fail the backend's nullable|date rules.
 */
const toFormData = (payload: ExpensePayload): FormData => {
  const form = new FormData();

  form.append('date', payload.date);
  form.append('amount', String(payload.amount));
  form.append('expense_type', payload.expense_type);

  if (payload.category_id) form.append('category_id', String(payload.category_id));
  if (payload.receipt) form.append('receipt', payload.receipt);

  ([
    'payee', 'description', 'invoice_no', 'reference_no', 'provider',
    'supplier', 'location', 'barangay', 'city', 'received_date',
  ] as const).forEach((field) => {
    const value = payload[field];
    if (value !== undefined && value !== null && value !== '') {
      form.append(field, String(value));
    }
  });

  return form;
};

export const getExpenses = async (filters?: ExpenseFilters): Promise<Expense[]> => {
  try {
    const response = await apiClient.get<ApiResponse<Expense[]>>('/expenses-logs', {
      params: toQueryParams(filters),
    });
    if (response.data.status === 'success' && Array.isArray(response.data.data)) {
      return response.data.data;
    }
    return [];
  } catch (error: any) {
    console.error('Error fetching expenses:', error);
    throw error;
  }
};

export const getExpenseSummary = async (categoryId?: number | ''): Promise<ExpenseSummary | null> => {
  try {
    const response = await apiClient.get<ApiResponse<ExpenseSummary>>('/expenses-logs/summary', {
      params: categoryId ? { category_id: String(categoryId) } : {},
    });
    if (response.data.status === 'success') return response.data.data;
    return null;
  } catch (error: any) {
    console.error('Error fetching expense summary:', error);
    return null;
  }
};

export const createExpense = async (payload: ExpensePayload): Promise<Expense> => {
  const response = await apiClient.post<ApiResponse<Expense>>('/expenses-logs', toFormData(payload), {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  if (response.data.status === 'success' && response.data.data) return response.data.data;
  throw new Error(response.data.message || 'Failed to record expense');
};

export const updateExpense = async (id: string, payload: ExpensePayload): Promise<Expense> => {
  const form = toFormData(payload);
  // PHP does not populate $_FILES on a real PUT body, so multipart updates are
  // POSTed with a method override. The route accepts both.
  form.append('_method', 'PUT');

  const response = await apiClient.post<ApiResponse<Expense>>(`/expenses-logs/${id}`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  if (response.data.status === 'success' && response.data.data) return response.data.data;
  throw new Error(response.data.message || 'Failed to update expense');
};

export const deleteExpense = async (id: string): Promise<void> => {
  await apiClient.delete(`/expenses-logs/${id}`);
};

/** Pulls the CSV as a blob and triggers a browser download of the current filters. */
export const exportExpensesCsv = async (filters?: ExpenseFilters): Promise<void> => {
  const response = await apiClient.get('/expenses-logs/export', {
    params: toQueryParams(filters),
    responseType: 'blob',
  });

  const url = window.URL.createObjectURL(new Blob([response.data as BlobPart], { type: 'text/csv' }));
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('download', `expenses_${new Date().toISOString().slice(0, 10)}.csv`);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};
