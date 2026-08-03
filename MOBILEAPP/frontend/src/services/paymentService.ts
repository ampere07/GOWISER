import AsyncStorage from '@react-native-async-storage/async-storage';
import axios from 'axios';
import { API_BASE_URL } from '../config/api';

// No need to redeclare API_BASE_URL or getApiBaseUrl

export interface PlanChangeQuote {
  status: string;
  /** false when the account is outside the never-paid prepaid onboarding window. */
  eligible: boolean;
  reason?: string | null;
  plan?: string;
  plan_amount?: number;
  vat?: number;
  withholding?: number;
  /** The re-priced total the customer would actually be charged. */
  amount?: number;
  previous_amount?: number;
}

export interface PendingPayment {
  reference_no: string;
  payment_url: string;
  /** Gross — the bill plus the convenience fee, i.e. what the gateway will collect. */
  amount: number;
  /** Part of `amount` that is the convenience fee. 0 when none was charged. */
  convenience_fee?: number;
  status: string;
  payment_date: string;
}

export interface PaymentResponse {
  status: string;
  reference_no?: string;
  payment_url?: string;
  payment_id?: string;
  /** The amount applied to the customer's invoices — fee NOT included. */
  amount?: number;
  convenience_fee_percentage?: number;
  convenience_fee?: number;
  /** What the gateway actually collects: `amount` + `convenience_fee`. */
  total_charged?: number;
  account_balance?: number;
  message?: string;
  pending_payment?: PendingPayment;
}

export interface PaymentStatusResponse {
  status: string;
  payment?: {
    reference_no: string;
    amount: number;
    status: string;
    transaction_status: string;
    date_time: string;
  };
  message?: string;
}

export const paymentService = {
  getAccountBalance: async (accountNo: string): Promise<number> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        const parsed = JSON.parse(authData);
        token = parsed.token || '';
      }

      const response = await axios.post<{ status: string; account_balance?: number }>(
        `${API_BASE_URL}/payments/account-balance`,
        { account_no: accountNo },
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data.account_balance || 0;
    } catch (error: any) {
      console.error('Get account balance error:', error.response?.data || error.message);
      return 0;
    }
  },

  /**
   * Read-only: what an unpaid prepaid ONBOARDING bill would come to under a different plan.
   *
   * Returns eligible:false for any account outside that never-paid window — callers should then
   * keep their existing amount behaviour. The VAT/withholding maths stays server-side so the
   * client never reimplements it.
   */
  quotePlanChange: async (accountNo: string, planId: number): Promise<PlanChangeQuote | null> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';
      if (authData) {
        token = JSON.parse(authData).token || '';
      }

      const response = await axios.post<PlanChangeQuote>(
        `${API_BASE_URL}/payments/quote-plan-change`,
        { account_no: accountNo, plan_id: planId },
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data;
    } catch (error: any) {
      // Non-fatal: the caller falls back to the plan price.
      console.error('Quote plan change error:', error.response?.data || error.message);
      return null;
    }
  },

  /**
   * The convenience fee rate added on top of an online payment, as a percentage (2.5 = 2.5%).
   *
   * Returns 0 on any failure so a payment screen never blocks on it — the server computes the
   * real charge at checkout regardless of what was disclosed here.
   */
  getConvenienceFeePercentage: async (): Promise<number> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        token = JSON.parse(authData).token || '';
      }

      const response = await axios.get<{ status: string; convenience_fee_percentage?: number }>(
        `${API_BASE_URL}/payments/convenience-fee`,
        {
          headers: {
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return Number(response.data.convenience_fee_percentage) || 0;
    } catch (error: any) {
      console.error('Get convenience fee error:', error.response?.data || error.message);
      return 0;
    }
  },

  checkPendingPayment: async (accountNo: string): Promise<PendingPayment | null> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        const parsed = JSON.parse(authData);
        token = parsed.token || '';
      }

      const response = await axios.post<{ status: string; pending_payment?: PendingPayment }>(
        `${API_BASE_URL}/payments/check-pending`,
        { account_no: accountNo },
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data.pending_payment || null;
    } catch (error: any) {
      console.error('Check pending payment error:', error.response?.data || error.message);
      return null;
    }
  },

  /**
   * Create a payment link.
   *
   * `planId` is only sent by prepaid customers who picked a plan in the Pay Now modal. The
   * backend holds it on the pending payment and, once the payment settles, either queues the
   * switch for when the current prepaid period lapses or applies it immediately if the period
   * had already expired.
   *
   * `activateNow` overrides that queueing: the plan starts the moment the payment settles and the
   * days left on the current one are forfeited. Only sent alongside a planId; the backend ignores
   * it without a genuine plan switch.
   */
  createPayment: async (
    accountNo: string,
    amount: number,
    redirectUrl?: string,
    planId?: number | null,
    activateNow?: boolean
  ): Promise<PaymentResponse> => {
    try {
      console.log('Payment Service - Creating payment:', { accountNo, amount });

      if (!accountNo || accountNo.trim() === '') {
        throw new Error('Account number is missing from user session. Please log in again.');
      }

      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        const parsed = JSON.parse(authData);
        token = parsed.token || '';
        console.log('Auth data:', {
          hasToken: !!token,
          accountNo: parsed.account_no,
          username: parsed.username
        });
      }

      const payload: any = {
        account_no: accountNo,
        amount: amount
      };

      if (redirectUrl) {
        payload.redirect_url = redirectUrl;
      }

      if (planId) {
        payload.plan_id = planId;
        // Only meaningful with a plan. Sent explicitly (not omitted when false) so the row records
        // a deliberate "queue it" rather than an absence the server has to guess at.
        payload.activate_now = !!activateNow;
      }

      console.log('Payment payload:', payload);

      const response = await axios.post<PaymentResponse>(
        `${API_BASE_URL}/payments/create`,
        payload,
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data as PaymentResponse;
    } catch (error: any) {
      console.error('Payment creation error:', error.response?.data || error.message);

      if (error.response?.data) {
        throw new Error(error.response.data.message || 'Payment creation failed');
      }
      throw new Error(error.message || 'Network error. Please check your connection.');
    }
  },

  checkPaymentStatus: async (referenceNo: string): Promise<PaymentStatusResponse> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        const parsed = JSON.parse(authData);
        token = parsed.token || '';
      }

      const response = await axios.post<PaymentStatusResponse>(
        `${API_BASE_URL}/payments/status`,
        {
          reference_no: referenceNo
        },
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data as PaymentStatusResponse;
    } catch (error: any) {
      if (error.response?.data) {
        throw new Error(error.response.data.message || 'Failed to check payment status');
      }
      throw new Error('Network error. Please check your connection.');
    }
  },

  cancelPayment: async (referenceNo: string): Promise<{ status: string; message?: string }> => {
    try {
      const authData = await AsyncStorage.getItem('authData');
      let token = '';

      if (authData) {
        const parsed = JSON.parse(authData);
        token = parsed.token || '';
      }

      const response = await axios.post<{ status: string; message?: string }>(
        `${API_BASE_URL}/payments/cancel`,
        { reference_no: referenceNo },
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          }
        }
      );

      return response.data;
    } catch (error: any) {
      if (error.response?.data) {
        throw new Error(error.response.data.message || 'Failed to cancel payment');
      }
      throw new Error('Network error. Please check your connection.');
    }
  }
};
