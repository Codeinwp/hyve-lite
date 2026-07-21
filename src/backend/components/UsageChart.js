/**
 * External dependencies.
 */
import {
	Chart,
	CategoryScale,
	Filler,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useEffect, useRef } from '@wordpress/element';

Chart.register(
	CategoryScale,
	Filler,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip
);

const UsageChart = ( { labels, messages, sessions } ) => {
	const canvasRef = useRef( null );
	const chartRef = useRef( null );

	useEffect( () => {
		if ( ! canvasRef.current ) {
			return;
		}

		const instance = new Chart( canvasRef.current.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: [],
				datasets: [
					{
						label: __( 'Messages', 'hyve-lite' ),
						data: [],
						borderColor: '#2271b1',
						backgroundColor: 'rgba(34, 113, 177, 0.08)',
						fill: true,
						tension: 0.3,
						pointRadius: 0,
						borderWidth: 2,
					},
					{
						label: __( 'Sessions', 'hyve-lite' ),
						data: [],
						borderColor: '#dba617',
						backgroundColor: 'transparent',
						fill: false,
						tension: 0.3,
						pointRadius: 0,
						borderWidth: 2,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: {
					mode: 'index',
					intersect: false,
				},
				plugins: {
					legend: {
						position: 'top',
						align: 'end',
						labels: {
							boxWidth: 12,
							boxHeight: 12,
						},
					},
				},
				scales: {
					x: {
						ticks: {
							maxTicksLimit: 12,
						},
					},
					y: {
						beginAtZero: true,
						ticks: {
							precision: 0,
						},
					},
				},
			},
		} );

		chartRef.current = instance;

		return () => {
			instance.destroy();
			chartRef.current = null;
		};
	}, [] );

	useEffect( () => {
		const instance = chartRef.current;

		if ( ! instance ) {
			return;
		}

		instance.data.labels = labels;
		instance.data.datasets[ 0 ].data = messages;
		instance.data.datasets[ 1 ].data = sessions;
		instance.update();
	}, [ labels, messages, sessions ] );

	return (
		<div className="hyve-next-chart">
			<canvas
				ref={ canvasRef }
				role="img"
				aria-label={ __(
					'Messages and sessions per day',
					'hyve-lite'
				) }
			></canvas>
		</div>
	);
};

export default UsageChart;
