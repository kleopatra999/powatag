$(function() {

	generatePowaTag('https://live.powatag.com/generator/powatag', 'xxxx-xxxx-xxxx-xxxx', 'item-sku');

	jQuery("#powatagPopupLink[rel]").overlay(
		{
			close : '.powaTagClose'		
		}
	);

	jQuery('.powaTagClose').bind('click', function() {
		jQuery(this).parent('#powaTagPopup').hide();
	});

	var a = jQuery('#powaTagPopup .powaTagRight').contents();

	jQuery('#powaTag').mouseover(function(){
		jQuery("#powaTagPopup .powaTagRight").empty();
		jQuery("#powaTagZoom .powaTagContent").empty().append(a);
		jQuery("#powaTagZoom").show();
	});

	jQuery('#powaTag').mouseout(function(){
		jQuery("#powaTagZoom").hide();
		jQuery("#powaTagPopup .powaTagRight").append(a);
	});

});